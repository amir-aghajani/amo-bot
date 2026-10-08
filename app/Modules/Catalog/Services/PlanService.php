<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Exceptions\PlanInUseException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Everything the admin does with plans: validation with Persian messages, create/update/duplicate, ordering, deletion
 * guarded by history, and the presentation the screen consumes.
 *
 * A plan is sold on one or more servers (PlanServer entries): the whole server — every inbound it marks selectable,
 * resolved at purchase time — or a hand-picked set of its inbounds. The customer chooses the server when buying and gets
 * one client on all of that entry's inbounds. The admin may attach any server; an entry customers are not offered now
 * says why (`unsellable_reason`, ServerSelector::problem()), and so does a plan the bot does not show at all
 * (ServerSelector::judge(), the judgement the bot's shop makes).
 */
final class PlanService
{
    private const NAME_MAX = 128;
    private const DESCRIPTION_MAX = 2000;
    private const MAX_DAYS = 3650;
    private const MAX_GB = 100000;
    private const MAX_DEVICES = 1000;

    /** What the plans list and the form read along with a plan. */
    private const RELATIONS = ['category', 'servers.inbounds'];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServerSelector $selector,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(array $input): Plan
    {
        [$attributes, $entries] = $this->validate($input, null);

        return $this->db->transaction(function () use ($attributes, $entries): Plan {
            $plan = Plan::query()->create($attributes + ['sort' => Sorting::next(Plan::class)]);
            $this->storeServers($plan, $entries);

            return $plan;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Plan $plan, array $input): Plan
    {
        [$attributes, $entries] = $this->validate($input, $plan);

        return $this->db->transaction(function () use ($plan, $attributes, $entries): Plan {
            $plan->fill($attributes)->save();
            $this->storeServers($plan, $entries);

            return $plan;
        });
    }

    public function setActive(Plan $plan, bool $active): Plan
    {
        $plan->forceFill(['is_active' => $active])->save();

        return $plan;
    }

    /** A copy to edit into a variant: same numbers and servers, switched off, placed right after the original. */
    public function duplicate(Plan $plan): Plan
    {
        return $this->db->transaction(function () use ($plan): Plan {
            Plan::query()->where('sort', '>', $plan->sort)->increment('sort');

            $copy = $plan->replicate();
            $copy->name = mb_substr($plan->name . ' (کپی)', 0, self::NAME_MAX);
            $copy->is_active = false;
            $copy->sort = $plan->sort + 1;
            $copy->save();

            foreach ($plan->servers()->with('inbounds')->get() as $entry) {
                $copied = $copy->servers()->create(['server_id' => $entry->server_id, 'all_inbounds' => $entry->all_inbounds, 'sort' => $entry->sort]);
                $copied->inbounds()->attach($entry->inbounds->pluck('id')->all());
            }

            return $copy;
        });
    }

    /**
     * @throws PlanInUseException once an order or a service points at it (its `counts`, which the screen reads to offer
     *                            no delete): it is switched off instead
     */
    public function delete(Plan $plan): void
    {
        ['orders' => $orders, 'subscriptions' => $subscriptions] = self::counts([$plan->id])[$plan->id];
        if ($orders > 0 || $subscriptions > 0) {
            throw new PlanInUseException(sprintf(
                'این پلن در %s سفارش و %s اشتراک استفاده شده و برای حفظ تاریخچه قابل حذف نیست؛ به‌جای حذف، آن را غیرفعال کنید.',
                Persian::number($orders),
                Persian::number($subscriptions),
            ));
        }

        $plan->delete();
    }

    /**
     * The order the admin arranged; ids not listed keep their relative order after the listed ones.
     *
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        Sorting::reorder(Plan::class, $ids);
    }

    /**
     * Every plan in display order with its figures.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->presentAll($this->withRelations(Plan::query())->oldest('sort')->oldest('id')->get());
    }

    /**
     * One plan as the screen shows it.
     *
     * @return array<string, mixed>
     */
    public function present(Plan $plan): array
    {
        if (!$plan->relationLoaded('servers')) {
            $plan = $this->withRelations(Plan::query())->findOrFail($plan->id);
        }

        return $this->presentAll(new Collection([$plan]))[0];
    }

    /**
     * Plans as the screen shows them, their figures and why each is not offered read in one go for them all.
     *
     * @param Collection<int, Plan> $plans Read with their relations (withRelations())
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $plans): array
    {
        $counts = self::counts($plans->modelKeys());
        $reasons = $this->selector->judge($plans);

        return $plans->map(fn(Plan $plan): array => $this->row($plan, $counts[$plan->id], $reasons[$plan->id]))->values()->all();
    }

    /**
     * @param array{active_subscriptions: int, sales: int, orders: int, subscriptions: int} $counts
     * @return array<string, mixed>
     */
    private function row(Plan $plan, array $counts, ?string $unsellable): array
    {
        return [
            'id' => $plan->id,
            'category' => $plan->category === null ? null : ['id' => $plan->category->id, 'name' => $plan->category->name, 'is_active' => $plan->category->is_active],
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'duration_days' => $plan->duration_days,
            'traffic_gb' => $plan->traffic_gb,
            'ip_limit' => $plan->ip_limit,
            'servers' => $plan->servers->map($this->presentEntry(...))->values()->all(),
            'unsellable_reason' => $unsellable,
            'is_active' => $plan->is_active,
            'sort' => $plan->sort,
            'counts' => $counts,
            'created_at' => $plan->created_at->toIso8601String(),
            'updated_at' => $plan->updated_at->toIso8601String(),
        ];
    }

    /**
     * What the form needs: every server with its inbounds and whether it can sell now, and the categories to file the
     * plan under.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $servers = Server::query()->withCount(Server::activeServicesCount())->with(['inbounds' => static fn($q) => $q->oldest('id')])->oldest('sort')->oldest('id')->get();

        return [
            'categories' => PlanCategory::query()->oldest('sort')->oldest('id')->get()->map(static fn(PlanCategory $c): array => ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active])->values()->all(),
            'servers' => $servers->map(fn(Server $server): array => self::presentServer($server) + [
                'driver_label' => $this->providers->has($server->driver) ? $this->providers->label($server->driver) : $server->driver,
                'inbounds' => $server->inbounds->map(self::presentInbound(...))->values()->all(),
                'unsellable_reason' => $this->selector->problem($server),
            ])->values()->all(),
        ];
    }

    /**
     * Replace the plan's server entries with the validated set.
     *
     * @param list<array{server_id: int, all_inbounds: bool, inbound_ids: list<int>}> $entries
     */
    private function storeServers(Plan $plan, array $entries): void
    {
        $plan->servers()->delete();

        foreach ($entries as $position => $entry) {
            $row = $plan->servers()->create(['server_id' => $entry['server_id'], 'all_inbounds' => $entry['all_inbounds'], 'sort' => $position + 1]);
            if (!$entry['all_inbounds']) {
                $row->inbounds()->attach($entry['inbound_ids']);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<array{server_id: int, all_inbounds: bool, inbound_ids: list<int>}>} The plan's columns and its server entries
     * @throws ValidationException
     */
    private function validate(array $input, ?Plan $existing): array
    {
        $errors = [];

        $name = Input::text($input, 'name');
        if ($name === '') {
            $errors['name'][] = 'نام پلن را وارد کنید.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'][] = Validation::tooLong('نام پلن', self::NAME_MAX);
        }

        $description = Input::text($input, 'description');
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'][] = Validation::tooLong('توضیحات', self::DESCRIPTION_MAX);
        }

        $price = Input::amount($input, 'price');
        if ($price === null) {
            $errors['price'][] = 'قیمت را به تومان و بدون اعشار وارد کنید؛ برای پلن رایگان 0 بگذارید.';
        }

        $duration = Input::integer($input, 'duration_days');
        if ($duration === null || $duration > self::MAX_DAYS) {
            $errors['duration_days'][] = sprintf('مدت اعتبار باید عددی بین 0 (بدون محدودیت) تا %d روز باشد.', self::MAX_DAYS);
        }

        // An agent's bot sells from their prepaid traffic: a plan of unlimited traffic is nothing it could pay for, so
        // there 0 is no "unlimited" either.
        $agentShop = !CurrentBot::isMain();
        $traffic = Input::decimal($input, 'traffic_gb');
        if ($traffic === null || (float) $traffic > self::MAX_GB) {
            $errors['traffic_gb'][] = $agentShop
                ? sprintf('حجم پلن را به گیگابایت وارد کنید؛ بیشتر از 0 و حداکثر %d.', self::MAX_GB)
                : sprintf('حجم را به گیگابایت وارد کنید؛ 0 یعنی نامحدود، حداکثر %d.', self::MAX_GB);
        } elseif ($agentShop && (float) $traffic <= 0) {
            $errors['traffic_gb'][] = 'در فروشگاه نمایندگی حجم پلن باید مشخص باشد؛ پلن حجم نامحدود فروخته نمی‌شود.';
        }

        $devices = Input::integer($input, 'ip_limit');
        if ($devices === null || $devices > self::MAX_DEVICES) {
            $errors['ip_limit'][] = sprintf('تعداد دستگاه باید عددی بین 0 (نامحدود) تا %d باشد.', self::MAX_DEVICES);
        }

        // Blank, null or 0 = no category.
        $categoryId = Input::text($input, 'category_id') === '' ? 0 : Input::integer($input, 'category_id');
        if ($categoryId === null || ($categoryId > 0 && !PlanCategory::query()->whereKey($categoryId)->exists())) {
            $errors['category_id'][] = 'دسته انتخاب‌شده وجود ندارد.';
        }

        $entries = $this->validateServers($input['servers'] ?? [], $errors);

        ValidationException::ifAny($errors);

        return [[
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'price' => Money::normalize((int) $price),
            'duration_days' => $duration,
            'traffic_gb' => round((float) $traffic, 2),
            'ip_limit' => $devices,
            'is_active' => Input::active($input, $existing?->is_active),
        ], $entries];
    }

    /**
     * `servers`: [{server_id, all_inbounds, inbound_ids: []}, …] — at least one, each server once, pinned inbounds of
     * their own server and enabled.
     *
     * @param array<string, list<string>> $errors
     * @return list<array{server_id: int, all_inbounds: bool, inbound_ids: list<int>}>
     */
    private function validateServers(mixed $input, array &$errors): array
    {
        if (!is_array($input) || $input === []) {
            $errors['servers'][] = 'دست‌کم یک سرور برای این پلن انتخاب کنید.';

            return [];
        }

        $entries = [];
        $seen = [];
        foreach (array_values($input) as $index => $item) {
            $item = is_array($item) ? $item : [];
            $server = Server::query()->find(Input::integer($item, 'server_id') ?? 0);
            if ($server === null) {
                $errors['servers'][] = sprintf('ردیف %s: سرور انتخاب‌شده وجود ندارد.', Persian::digits($index + 1));
                continue;
            }
            if (isset($seen[$server->id])) {
                $errors['servers'][] = "«{$server->name}» بیش از یک بار اضافه شده است؛ اینباندهایش را در یک ردیف جمع کنید.";
                continue;
            }
            $seen[$server->id] = true;

            $all = Input::truthy($item['all_inbounds'] ?? false);
            $inboundIds = [];
            if (!$all) {
                $requested = array_values(array_unique(array_map(intval(...), is_array($item['inbound_ids'] ?? null) ? $item['inbound_ids'] : [])));
                if ($requested === []) {
                    $errors['servers'][] = "برای «{$server->name}» یا کل سرور را انتخاب کنید یا دست‌کم یک اینباند.";
                    continue;
                }
                $inbounds = ServerInbound::query()->where('server_id', $server->id)->whereIn('id', $requested)->get()->keyBy('id');
                foreach ($requested as $id) {
                    $inbound = $inbounds->get($id);
                    if ($inbound === null) {
                        $errors['servers'][] = "یکی از اینباندهای انتخاب‌شده متعلق به «{$server->name}» نیست.";
                        continue 2;
                    }
                    if (!$inbound->enabled) {
                        $errors['servers'][] = "اینباند «{$inbound->remark}» روی «{$server->name}» غیرفعال است و قابل فروش نیست.";
                        continue 2;
                    }
                    $inboundIds[] = $id;
                }
            }

            $entries[] = ['server_id' => $server->id, 'all_inbounds' => $all, 'inbound_ids' => $inboundIds];
        }

        return $entries;
    }

    /**
     * Plans read with what their presentation needs: the category, the entries with their servers (their active services
     * counted, for the capacity, and their inbounds, for what a whole-server entry sells) and pinned inbounds.
     *
     * @param Builder<Plan> $query
     * @return Builder<Plan>
     */
    private function withRelations(Builder $query): Builder
    {
        return $query->with([...self::RELATIONS, 'servers.server' => static fn(Relation $server) => $server->withCount(Server::activeServicesCount())->with('inbounds')]);
    }

    /**
     * Each plan's figures, one query a table for every plan asked: its services — every one, and the running ones —
     * and its orders — every one, and those sold (Order::SOLD). Every id asked for gets an entry, zeros included. Any
     * order or service at all keeps a plan from being deleted (delete()). The plans are the shop's, and so are their
     * services and orders: without the shop's own filter on top, each count is read off the index on (plan_id, status)
     * alone.
     *
     * @param array<int> $planIds
     * @return array<int, array{active_subscriptions: int, sales: int, orders: int, subscriptions: int}>
     */
    private static function counts(array $planIds): array
    {
        $services = self::tally(Subscription::acrossShops(), $planIds, [SubscriptionStatus::Active->value]);
        $orders = self::tally(Order::query()->withoutGlobalScope(CurrentBot::SCOPE), $planIds, Order::SOLD);

        $counts = [];
        foreach ($planIds as $id) {
            $counts[$id] = [
                'active_subscriptions' => $services[$id]['some'] ?? 0,
                'sales' => $orders[$id]['some'] ?? 0,
                'orders' => $orders[$id]['all'] ?? 0,
                'subscriptions' => $services[$id]['all'] ?? 0,
            ];
        }

        return $counts;
    }

    /**
     * The rows of `$query` each plan has — all of them, and those in one of `$statuses` —, in one grouped read.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param Builder<TModel> $query
     * @param array<int> $planIds
     * @param list<string> $statuses
     * @return array<int, array{all: int, some: int}>
     */
    private static function tally(Builder $query, array $planIds, array $statuses): array
    {
        $marks = implode(', ', array_fill(0, count($statuses), '?'));
        $rows = $query->toBase()
            ->whereIn('plan_id', $planIds)
            ->selectRaw("plan_id, count(*) as total, sum(case when status in ({$marks}) then 1 else 0 end) as in_status", $statuses)
            ->groupBy('plan_id')
            ->get();

        $tally = [];
        foreach ($rows as $row) {
            $tally[(int) $row->plan_id] = ['all' => (int) $row->total, 'some' => (int) $row->in_status];
        }

        return $tally;
    }

    /**
     * One server entry as the screen shows it: which inbounds a purchase gets today, and why customers are not offered
     * it now, if they are not.
     *
     * @return array<string, mixed>
     */
    private function presentEntry(PlanServer $entry): array
    {
        $inbounds = $entry->all_inbounds ? $entry->sellableInbounds() : $entry->inbounds->sortBy('id');

        return [
            'server' => self::presentServer($entry->server),
            'all_inbounds' => $entry->all_inbounds,
            'inbounds' => $inbounds->map(self::presentInbound(...))->values()->all(),
            'unsellable_reason' => $this->selector->problem($entry->server, $entry),
        ];
    }

    /**
     * The server as the plan form and the plans table know it: `serves_subscriptions` as the servers screen shows it
     * (null = never checked, false = the panel gives no links).
     *
     * @return array{id: int, name: string, is_active: bool, serves_subscriptions: bool|null}
     */
    private static function presentServer(Server $server): array
    {
        return ['id' => $server->id, 'name' => $server->name, 'is_active' => $server->is_active, 'serves_subscriptions' => $server->serves_subscriptions];
    }

    /** @return array{id: int, remark: string|null, protocol: string|null, port: int|null, enabled: bool, is_selectable: bool} */
    private static function presentInbound(ServerInbound $inbound): array
    {
        return [
            'id' => $inbound->id,
            'remark' => $inbound->remark,
            'protocol' => $inbound->protocol,
            'port' => $inbound->port,
            'enabled' => $inbound->enabled,
            'is_selectable' => $inbound->is_selectable,
        ];
    }
}
