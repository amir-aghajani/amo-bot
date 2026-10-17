<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Exceptions\PlanInUseException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ExtrasSettings;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Modules\Telegram\Keyboard\KeyboardLayout;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Plans as the panels keep them: validation, create/update/duplicate, ordering, deletion (refused once orders or
 * services point at it), presentation, and changing several plans' prices at once (previewPrices(), changePrices()).
 *
 * A plan is sold on one or more servers (PlanServer): the whole server or chosen inbounds. The admin may attach any
 * server; what customers are not offered now says why (`unsellable_reason`, ServerSelector). Beside its price a plan may
 * have a first-purchase and an agent price (Orders\Services\Pricing decides who pays which), its own extras prices, a
 * checkout note and its button's look in the bot.
 *
 * In an agent's shop the owner may keep a plan from sale (`hidden_by_owner`) and servers from the shop: only the owner's
 * agents screen sets them (AgentRestrictions) — nothing here does, and a copy of a plan stays kept —,
 * and the form neither offers nor takes a server kept from the shop that the plan was not on already.
 */
final class PlanService
{
    private const NAME_MAX = 128;
    private const DESCRIPTION_MAX = 2000;
    private const CHECKOUT_NOTE_MAX = 500;
    /** The most a plan's price may be: what its column keeps. */
    private const PRICE_MAX = '999999999999';

    /** The prices a change of several plans' prices may move: the plan's own, and the ones a customer may pay instead. */
    private const PRICES = ['price', 'first_purchase_price', 'agent_price'];
    /** The steps a changed price is rounded to (1: whole Toman). */
    private const ROUNDINGS = [1, 100, 1000, 10000];
    private const PERCENT_MAX = 1000;

    private const PRICE_WORDS = ['price' => 'قیمت', 'first_purchase_price' => 'قیمت خرید اول', 'agent_price' => 'قیمت نماینده'];
    private const MAX_DAYS = 3650;
    private const MAX_HOURS = self::MAX_DAYS * ServiceTerms::HOURS_A_DAY;
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
     * Deleted while no order or service points at it — asked by the statement that deletes it, so one made since the
     * screen read its counts (an order opened that moment) keeps it, rather than losing its plan to the foreign key.
     *
     * @throws PlanInUseException once an order or a service points at it (its `counts`, which the screen reads to offer
     *                            no delete): it is switched off instead
     */
    public function delete(Plan $plan): void
    {
        $deleted = $plan->newModelQuery()->whereKey($plan->id)
            ->whereNotExists(static fn(QueryBuilder $orders) => $orders->from('orders')->whereColumn('orders.plan_id', 'plans.id'))
            ->whereNotExists(static fn(QueryBuilder $services) => $services->from('subscriptions')->whereColumn('subscriptions.plan_id', 'plans.id'))
            ->delete();
        if ($deleted === 1) {
            return;
        }

        ['orders' => $orders, 'subscriptions' => $subscriptions] = self::counts([$plan->id])[$plan->id];
        if ($orders > 0 || $subscriptions > 0) {
            throw new PlanInUseException(sprintf(
                'این پلن در %s سفارش و %s اشتراک استفاده شده و برای حفظ تاریخچه قابل حذف نیست؛ به‌جای حذف، آن را غیرفعال کنید.',
                Persian::number($orders),
                Persian::number($subscriptions),
            ));
        }
        // Deleted meanwhile (another tab).
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
            'checkout_note' => $plan->checkout_note,
            'price' => $plan->price,
            'duration_days' => ServiceTerms::daysOf($plan->duration_hours),
            'duration_hours' => $plan->duration_hours,
            'traffic_gb' => $plan->traffic_gb,
            'ip_limit' => $plan->ip_limit,
            'first_purchase_price' => $plan->first_purchase_price,
            'agent_price' => $plan->agent_price,
            'extras' => self::presentExtras($plan),
            'button_style' => $plan->button_style,
            'button_row' => $plan->button_row,
            'servers' => $plan->servers->map($this->presentEntry(...))->values()->all(),
            'unsellable_reason' => $unsellable,
            'is_active' => $plan->is_active,
            'hidden_by_owner' => (bool) $plan->hidden_by_owner,
            'sort' => $plan->sort,
            'counts' => $counts,
            'created_at' => $plan->created_at->toIso8601String(),
            'updated_at' => $plan->updated_at->toIso8601String(),
        ];
    }

    /**
     * The plan's own prices of the extras, as its form shows them; null — it sells them at the shop's.
     *
     * @return array<string, mixed>|null
     */
    private static function presentExtras(Plan $plan): ?array
    {
        $own = $plan->extras;

        return $own === null ? null : ExtrasSettings::planForm()->present(static fn(Field $field): mixed => $own[$field->key] ?? null);
    }

    /**
     * What the form needs: every server with its inbounds and whether it can sell now — but the servers the owner keeps
     * from this shop —, and the categories to file the plan under.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $servers = Server::shown()->withCount(Server::activeServicesCount())->with(['inbounds' => static fn($q) => $q->oldest('id')])->oldest('sort')->oldest('id')->get();

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

        $duration = self::term($input, $errors);

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
            $errors['ip_limit'][] = sprintf('تعداد کاربر باید عددی بین 0 (نامحدود) تا %d باشد.', self::MAX_DEVICES);
        }

        // Blank, null or 0 = no category.
        $categoryId = Input::text($input, 'category_id') === '' ? 0 : Input::integer($input, 'category_id');
        if ($categoryId === null || ($categoryId > 0 && !PlanCategory::query()->whereKey($categoryId)->exists())) {
            $errors['category_id'][] = 'دسته انتخاب‌شده وجود ندارد.';
        }

        $entries = $this->validateServers($input['servers'] ?? [], $existing, $errors);
        // Left out, a plan keeps its own prices — a form made before them sends none —; a new one sells at the shop's.
        $extras = array_key_exists('extras', $input) ? self::validateExtras($input['extras'], $errors) : $existing?->extras;
        $firstPrice = self::otherPrice($input, 'first_purchase_price', $existing?->first_purchase_price, $price, $errors, 'قیمت خرید اول', 'برای خرید اول رایگان 0 بگذارید');
        $agentPrice = self::otherPrice($input, 'agent_price', $existing?->agent_price, $price, $errors, 'قیمت نماینده', 'برای نماینده رایگان 0 بگذارید');
        $note = self::checkoutNote($input, $existing, $errors);
        [$style, $row] = self::button($input, $existing, $errors);

        ValidationException::ifAny($errors);

        return [[
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'checkout_note' => $note,
            'price' => Money::normalize((int) $price),
            'duration_hours' => $duration,
            'traffic_gb' => round((float) $traffic, 2),
            'ip_limit' => $devices,
            'extras' => $extras,
            'first_purchase_price' => $firstPrice,
            'agent_price' => $agentPrice,
            'button_style' => $style,
            'button_row' => $row,
            'is_active' => Input::active($input, $existing?->is_active),
        ], $entries];
    }

    /**
     * A first-purchase or agent price: whole Toman below the price (0: free), blank or null for none; left out, the plan
     * keeps its own (a new one has none).
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function otherPrice(array $input, string $field, ?string $kept, ?int $price, array &$errors, string $words, string $free): ?string
    {
        $amount = $kept;
        if (array_key_exists($field, $input)) {
            $amount = null;
            if (Input::text($input, $field) !== '') {
                $typed = Input::amount($input, $field);
                if ($typed === null) {
                    $errors[$field][] = "{$words} را به تومان و بدون اعشار وارد کنید؛ {$free}، و برای هیچ، خالی.";

                    return null;
                }
                $amount = Money::normalize($typed);
            }
        }
        if ($amount !== null && $price !== null && Money::compare($amount, $price) >= 0) {
            $errors[$field][] = "{$words} باید کمتر از قیمت پلن باشد.";
        }

        return $amount;
    }

    /**
     * The checkout note («یادداشت پیش‌فاکتور»), line breaks kept, blank for none; left out, the plan keeps its own.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function checkoutNote(array $input, ?Plan $existing, array &$errors): ?string
    {
        if (!array_key_exists('checkout_note', $input)) {
            return $existing?->checkout_note;
        }
        $note = str_replace("\r\n", "\n", Input::text($input, 'checkout_note'));
        if (mb_strlen($note) > self::CHECKOUT_NOTE_MAX) {
            $errors['checkout_note'][] = Validation::tooLong('یادداشت پیش‌فاکتور', self::CHECKOUT_NOTE_MAX);
        }

        return $note === '' ? null : $note;
    }

    /**
     * The plan's button in the bot: its colour (KeyboardLayout::STYLES, blank for the client's default) and how many
     * buttons share its row; each left out keeps the plan's own.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     * @return array{string|null, int}
     */
    private static function button(array $input, ?Plan $existing, array &$errors): array
    {
        $style = $existing?->button_style;
        if (array_key_exists('button_style', $input)) {
            $style = Input::text($input, 'button_style');
            if ($style === '') {
                $style = null;
            } elseif (!in_array($style, KeyboardLayout::STYLES, true)) {
                $errors['button_style'][] = KeyboardLayout::STYLE_REFUSED;
            }
        }

        $row = $existing !== null ? $existing->button_row : 1;
        if (array_key_exists('button_row', $input)) {
            $row = Input::integerOf($input['button_row']) ?? 0;
            if ($row < 1 || $row > KeyboardLayout::ROW_MOST) {
                $errors['button_row'][] = KeyboardLayout::ROW_REFUSED;
            }
        }

        return [$style, $row];
    }

    /**
     * Previews a change of several plans' prices as the screen shows it, saving nothing: each plan with its prices before
     * and after, and the problem that keeps it from the change.
     *
     * @param array<string, mixed> $input
     * @return list<array{id: int, name: string, before: array<string, string|null>, after: array<string, string|null>, problem: string|null}>
     * @throws ValidationException every refusal of the change at once
     */
    public function previewPrices(array $input): array
    {
        return array_map(static fn(array $change): array => [
            'id' => $change['plan']->id,
            'name' => $change['plan']->name,
            'before' => $change['before'],
            'after' => $change['after'],
            'problem' => $change['problem'],
        ], $this->priceChanges($input));
    }

    /**
     * Applies priceChanges() to every plan in one transaction — or to none while any plan has a problem (each refused
     * under `plan_ids`).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function changePrices(array $input): void
    {
        $changes = $this->priceChanges($input);
        $problems = array_values(array_filter(array_map(static fn(array $change): ?string => $change['problem'] === null ? null : "پلن «{$change['plan']->name}»: {$change['problem']}", $changes)));
        ValidationException::ifAny($problems === [] ? [] : ['plan_ids' => $problems]);

        $this->db->transaction(static function () use ($changes): void {
            foreach ($changes as $change) {
                $change['plan']->forceFill($change['after'])->save();
            }
        });
    }

    /**
     * A change of several plans' prices, saving nothing: each plan's prices before and after, and the problem that keeps
     * it from the change (a price below zero or too large, a special price no longer below the price). The change is
     * `direction` by `mode` (percent or whole Toman) of `value`, rounded to `round_to`, on the `prices` named.
     *
     * @param array<string, mixed> $input
     * @return list<array{plan: Plan, before: array<string, string|null>, after: array<string, string|null>, problem: string|null}>
     * @throws ValidationException every refusal of the change at once
     */
    private function priceChanges(array $input): array
    {
        ['plans' => $plans, 'change' => $change, 'prices' => $prices] = self::priceChange($input);

        $changes = [];
        foreach ($plans as $plan) {
            $before = [];
            foreach (self::PRICES as $field) {
                $kept = $plan->getAttribute($field);
                $before[$field] = $kept === null ? null : Money::normalize($kept);
            }
            $after = $before;
            foreach ($prices as $field) {
                if ($before[$field] !== null) {
                    $after[$field] = $change($before[$field]);
                }
            }
            $changes[] = ['plan' => $plan, 'before' => $before, 'after' => $after, 'problem' => self::priceProblem($after)];
        }

        return $changes;
    }

    /**
     * Reads a price change, every refusal at once: the plans named, the change as a function of a price, and the prices
     * it moves.
     *
     * @param array<string, mixed> $input
     * @return array{plans: list<Plan>, change: \Closure(string): string, prices: list<string>}
     * @throws ValidationException
     */
    private static function priceChange(array $input): array
    {
        $errors = [];

        $ids = is_array($input['plan_ids'] ?? null) ? array_values(array_unique(array_map(static fn(mixed $id): int => Input::integerOf($id) ?? 0, $input['plan_ids']))) : [];
        $plans = [];
        if ($ids === []) {
            $errors['plan_ids'][] = 'دست‌کم یک پلن انتخاب کنید.';
        } elseif (count($ids) > Sorting::MAX_IDS || in_array(0, $ids, true)) {
            $errors['plan_ids'][] = 'یکی از پلن‌ها پیدا نشد؛ صفحه را دوباره باز کنید.';
        } else {
            $plans = Plan::query()->whereIn('id', $ids)->oldest('sort')->oldest('id')->get()->all();
            if (count($plans) !== count($ids)) {
                $errors['plan_ids'][] = 'یکی از پلن‌ها پیدا نشد؛ صفحه را دوباره باز کنید.';
            }
        }

        $direction = Input::text($input, 'direction');
        if (!in_array($direction, ['increase', 'decrease'], true)) {
            $errors['direction'][] = 'افزایش یا کاهش قیمت را انتخاب کنید.';
        }

        $mode = Input::text($input, 'mode');
        $value = null;
        if ($mode === 'percent') {
            $value = Input::decimal($input, 'value');
            $most = $direction === 'decrease' ? 100 : self::PERCENT_MAX;
            if ($value === null || bccomp($value, '0', 4) <= 0 || bccomp($value, (string) $most, 4) > 0) {
                $errors['value'][] = sprintf('درصد تغییر را عددی بیشتر از 0 و حداکثر %d وارد کنید.', $most);
                $value = null;
            }
        } elseif ($mode === 'amount') {
            $amount = Input::amount($input, 'value');
            if ($amount === null || $amount <= 0) {
                $errors['value'][] = 'مبلغ تغییر را به تومان و بدون اعشار، بیشتر از 0 وارد کنید.';
            } else {
                $value = (string) $amount;
            }
        } else {
            $errors['mode'][] = 'تغییر درصدی یا مبلغ ثابت را انتخاب کنید.';
        }

        $step = Input::integerOf($input['round_to'] ?? null);
        if (!in_array($step, self::ROUNDINGS, true)) {
            $errors['round_to'][] = 'گرد کردن را انتخاب کنید.';
        }

        $prices = is_array($input['prices'] ?? null) ? array_values(array_unique(array_filter($input['prices'], 'is_string'))) : [];
        if ($prices === [] || array_diff($prices, self::PRICES) !== []) {
            $errors['prices'][] = 'دست‌کم یکی از قیمت‌ها را برای تغییر انتخاب کنید.';
        }

        ValidationException::ifAny($errors);
        if ($value === null || $step === null) {
            throw new \LogicException('A change of prices without its value or its rounding passed its checks.');
        }

        $change = static function (string $amount) use ($direction, $mode, $value, $step): string {
            $delta = $mode === 'percent' ? bcdiv(bcmul($amount, $value, 6), '100', 6) : $value;
            $moved = $direction === 'increase' ? bcadd($amount, $delta, 6) : bcsub($amount, $delta, 6);
            $units = bcdiv($moved, (string) $step, 6);
            // The nearest step, a half going away from zero.
            $whole = bccomp($units, '0', 6) >= 0 ? bcadd($units, '0.5', 0) : bcsub($units, '0.5', 0);

            return Money::normalize(bcmul($whole, (string) $step, 0));
        };

        return ['plans' => $plans, 'change' => $change, 'prices' => array_values(array_intersect(self::PRICES, $prices))];
    }

    /**
     * Why a plan cannot take its prices as a change leaves them; null when it can.
     *
     * @param array<string, string|null> $prices
     */
    private static function priceProblem(array $prices): ?string
    {
        foreach (self::PRICES as $field) {
            $amount = $prices[$field];
            if ($amount === null) {
                continue;
            }
            if (Money::compare($amount, 0) < 0) {
                return self::PRICE_WORDS[$field] . ' کمتر از صفر می‌شود.';
            }
            if (Money::compare($amount, self::PRICE_MAX) > 0) {
                return self::PRICE_WORDS[$field] . ' بیشتر از حد مجاز می‌شود.';
            }
            if ($field !== 'price' && Money::compare($amount, (string) $prices['price']) >= 0) {
                return self::PRICE_WORDS[$field] . ' دیگر کمتر از قیمت پلن نمی‌ماند؛ آن را هم تغییر دهید یا خالی کنید.';
            }
        }

        return null;
    }

    /**
     * The plan's term in hours (0: never ends), from `duration_hours`, else `duration_days` × 24 (older clients send
     * days); each refused under its own name.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function term(array $input, array &$errors): ?int
    {
        if (!array_key_exists('duration_hours', $input) && array_key_exists('duration_days', $input)) {
            $days = Input::integer($input, 'duration_days');
            if ($days === null || $days > self::MAX_DAYS) {
                $errors['duration_days'][] = sprintf('مدت اعتبار باید عددی بین 0 (بدون محدودیت) تا %d روز باشد.', self::MAX_DAYS);

                return null;
            }

            return $days * ServiceTerms::HOURS_A_DAY;
        }

        $hours = Input::integer($input, 'duration_hours');
        if ($hours === null || $hours > self::MAX_HOURS) {
            $errors['duration_hours'][] = sprintf('مدت اعتبار باید عددی بین 0 (بدون محدودیت) تا %d ساعت (%d روز) باشد.', self::MAX_HOURS, self::MAX_DAYS);

            return null;
        }

        return $hours;
    }

    /**
     * The plan's own extras price list (ExtrasSettings::planForm()), refused under `extras.<field>`; null: the shop's.
     * A list sent as 0.3.0 sent it — no `devices_*` field — sells no devices, at the default bounds.
     *
     * @param array<string, list<string>> $errors
     * @return array<string, mixed>|null
     */
    private static function validateExtras(mixed $input, array &$errors): ?array
    {
        if ($input === null) {
            return null;
        }
        if (!is_array($input)) {
            $errors['extras'][] = 'قیمت‌های حجم، زمان و کاربر اضافه این پلن خوانده نشد.';

            return null;
        }

        $form = ExtrasSettings::planForm();
        foreach ($form->fields as $field) {
            if (str_starts_with($field->name, ExtraKind::Devices->value . '_') && !array_key_exists($field->name, $input)) {
                $input[$field->name] = $field->default;
            }
        }

        try {
            return $form->check($input, static fn(): mixed => null);
        } catch (ValidationException $refused) {
            foreach ($refused->errors() as $field => $messages) {
                $errors["extras.{$field}"] = $messages;
            }

            return null;
        }
    }

    /**
     * `servers`: [{server_id, all_inbounds, inbound_ids: []}, …] — at least one, each server once, pinned inbounds of
     * their own server and enabled. A server the owner keeps from this shop is not added; one the plan was on already
     * stays (nothing is sold on it).
     *
     * @param array<string, list<string>> $errors
     * @return list<array{server_id: int, all_inbounds: bool, inbound_ids: list<int>}>
     */
    private function validateServers(mixed $input, ?Plan $existing, array &$errors): array
    {
        if (!is_array($input) || $input === []) {
            $errors['servers'][] = 'دست‌کم یک سرور برای این پلن انتخاب کنید.';

            return [];
        }

        $hidden = CurrentBot::get()->hiddenServerIds();
        $had = $hidden === [] || $existing === null ? [] : $existing->servers()->pluck('server_id')->map(intval(...))->all();
        $entries = [];
        $seen = [];
        foreach (array_values($input) as $index => $item) {
            $item = is_array($item) ? $item : [];
            $server = Server::query()->find(Input::integer($item, 'server_id') ?? 0);
            if ($server === null) {
                $errors['servers'][] = sprintf('ردیف %s: سرور انتخاب‌شده وجود ندارد.', Persian::digits($index + 1));
                continue;
            }
            if (in_array($server->id, $hidden, true) && !in_array($server->id, $had, true)) {
                $errors['servers'][] = sprintf('«%s»: %s', $server->name, ServerSelector::HIDDEN_SERVER);
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
                // Each an id as integerOf() reads one: «2abc» or `true` is none, never inbound 2 or 1.
                $requested = [];
                foreach (is_array($item['inbound_ids'] ?? null) ? $item['inbound_ids'] : [] as $sent) {
                    $id = Input::integerOf($sent);
                    if ($id === null) {
                        $errors['servers'][] = "«{$server->name}»: شناسه اینباند انتخاب‌شده درست نیست.";
                        continue 2;
                    }
                    $requested[$id] = $id;
                }
                $requested = array_values($requested);
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
     * Plans read with what their presentation needs: the category, and the entries with their servers (active services
     * counted, inbounds loaded) and pinned inbounds.
     *
     * @param Builder<Plan> $query
     * @return Builder<Plan>
     */
    private function withRelations(Builder $query): Builder
    {
        return $query->with([...self::RELATIONS, 'servers.server' => static fn(Relation $server) => $server->withCount(Server::activeServicesCount())->with('inbounds')]);
    }

    /**
     * Each plan's figures in one grouped query per table: its services (all, running) and orders (all, sold). The shop
     * scope is left off — the plans are the shop's already — so each count is read off the (plan_id, status) index alone.
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
     * One server entry as the screen shows it: the inbounds a purchase gets today, and why customers are not offered it.
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
     * A server as the plan form shows it (`serves_subscriptions`: null never checked, false no links).
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
