<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\Actor;
use App\Modules\Subscriptions\Enums\SubscriptionAction;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * «🛍 سرویس‌ها» on a customer's card in the report group (CustomerCards: the checks, the look, the questions): their
 * services, newest first, PER_PAGE a page — each a line with its state's icon, client, plan, location and end, and a
 * button opening it.
 *
 * A service's view says all of it — client and the customer's own name for it, plan, location, state, traffic, end,
 * devices, link — with what its state allows (SubscriptionActions::allowed()): «🔄 همگام‌سازی», «➕ زمان و حجم» (a preset,
 * asked again first, or an amount typed in reply to the bot's question), «⏸ غیرفعال» (asked first) or «▶️ فعال», «🗑 حذف»
 * (asked first). What gives is done once: a preset's «✔️ بله» carries a nonce drawn with it (CustomerCards::once()), a
 * typed amount answers its question once (CustomerCards::reply()) — pressed or answered again, by anyone, nothing more is
 * given (in an agent's shop, nothing more is drawn from their pool). Every change goes through SubscriptionActions under
 * the presser's name (Actor::groupAdmin()), by its rules; a refusal is a popup and the view as the service now stands.
 * The admin's own account and an agent's are only read (CustomerCards::mayChange()); a service of anyone but this
 * customer is not found.
 */
final class CustomerServices implements GroupHandler, AnswersPrompts
{
    /** What the section's buttons' callback data starts with: `us:<action>:<customer id>[:<arg>…]`. */
    public const PREFIX = 'us:';

    /** The section opened from the card's front — the list, `[:<page>]` from 1. */
    public const OPEN = 'o';

    /** One service's view: `:<subscription id>`, as every action below. */
    private const VIEW = 'v';
    private const SYNC = 's';
    /**
     * «➕ زمان و حجم»: the presets; one asked again (`:<days>:<gb>`), then given (`:<days>:<gb>:<nonce>`); the question
     * for an amount typed.
     */
    private const EXTEND = 'x';
    private const EXTEND_ASK = 'xa';
    private const EXTEND_SET = 'xs';
    private const EXTEND_TYPED = 'xt';
    /** The switch off asked, then made; the switch on (nothing to ask). */
    private const DISABLE = 'd';
    private const DISABLE_SET = 'ds';
    private const ENABLE = 'e';
    /** The delete asked, then made. */
    private const DELETE = 'r';
    private const DELETE_SET = 'rs';

    /** «➕ زمان و حجم»'s presets: label, days, gigabytes. */
    private const PRESETS = [['➕ ۷ روز', 7, 0], ['➕ ۳۰ روز', 30, 0], ['➕ ۱۰ گیگ', 0, 10], ['➕ ۵۰ گیگ', 0, 50]];

    /** Services a page of the list shows. */
    private const PER_PAGE = 8;

    /** A name the words show. */
    private const NAME_MAX = 64;

    private const SECTION = 'سرویس‌ها';
    private const NONE = 'هیچ سرویسی ندارد.';
    private const NOT_FOUND = 'این سرویس پیدا نشد.';
    private const STALE = 'این کار برای این سرویس دیگر ممکن نیست؛ کارت به‌روز شد.';
    private const OPEN_ORDER = "⏳ سفارش \u{200E}#%d این سرویس هنوز تمام نشده است؛ تا آن موقع حذف نمی‌شود.";

    private const SYNCED = '🔄 از پنل خوانده شد.';
    private const GONE_FROM_PANEL = '⚫️ کلاینتش دیگر روی پنل نیست.';
    private const EXTENDED = '✅ %s به سرویس %s اضافه شد؛ مشتری خبردار شد.';
    private const DISABLED = '⏸ غیرفعال شد.';
    private const ENABLED = '▶️ فعال شد.';
    private const DELETED = '🗑 سرویس %s حذف شد.';

    private const HOW_MUCH = '➕ <b>چه مقدار به آن اضافه شود؟</b> مشتری خبردار می‌شود.';
    private const ASK_EXTEND = '❓ <b>%s به سرویس <code>%s</code> اضافه شود؟</b> مشتری خبردار می‌شود.';
    private const ASK_DISABLE = '❓ <b>سرویس <code>%s</code> غیرفعال شود؟</b> کلاینتش روی پنل خاموش می‌شود و مشتری خبردار می‌شود.';
    private const ASK_DELETE = '❓ <b>سرویس <code>%s</code> حذف شود؟</b> کلاینتش از پنل سرور هم پاک می‌شود و این کار برگشت ندارد.';
    private const CUSTOMER_TOLD = ' مشتری خبردار می‌شود.';

    private const TYPE_AMOUNT = 'چند روز و چند گیگ به سرویس %s اضافه شود؟ اول روز و بعد گیگ را با یک فاصله بنویسید؛ مثلا «30 10»، یا «30 0» فقط برای روز.';
    private const TYPE_PLACEHOLDER = '30 10';
    private const TYPE_HOW = 'اول تعداد روز و بعد گیگ را با یک فاصله بنویسید؛ مثلا «30 10»، یا «30 0» فقط برای روز.';

    public function __construct(
        private readonly CustomerCards $cards,
        private readonly SubscriptionActions $actions,
    ) {}

    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        if (!self::shaped($press)) {
            $this->cards->answer($press);

            return;
        }
        if ($press->action === self::OPEN) {
            $this->showList($press, $press->int(0) ?? 1);

            return;
        }

        $service = self::serviceOf($press->user, (int) $press->int(0));
        if ($service === null) {
            // Gone meanwhile, or a forged number of someone else's: the list as it is.
            $this->showList($press, 1, self::NOT_FOUND, alert: true);

            return;
        }

        try {
            match ($press->action) {
                self::SYNC => $this->sync($press, $service),
                self::EXTEND => $this->showExtend($press, $service),
                self::EXTEND_ASK => $this->askExtend($press, $service, (int) $press->int(1), (int) $press->int(2)),
                self::EXTEND_SET => $this->extend($press, $service, (int) $press->int(1), (int) $press->int(2), $press->args[3]),
                self::EXTEND_TYPED => $this->askAmount($press, $service),
                self::DISABLE => $this->askSwitchOff($press, $service),
                self::DISABLE_SET => $this->disable($press, $service),
                self::ENABLE => $this->enable($press, $service),
                self::DELETE => $this->askDelete($press, $service),
                self::DELETE_SET => $this->delete($press, $service),
                default => $this->showService($press, $service),
            };
        } catch (DomainRuleException $e) {
            // A press the card did not offer (a forged one, or a card older than the service), or one its rules or its
            // panel refused: refused in a popup, the view as the service now stands.
            $this->refresh($press, $service->id, CustomerCards::words($e), alert: true);
        } catch (ModelNotFoundException) {
            $this->showList($press, 1, self::NOT_FOUND, alert: true);
        }
    }

    /**
     * «✍️ مقدار دلخواه» answered: «30 10» — days, then gigabytes, in any digits — given to the service, the card back on
     * its view; anything else asked again. A refusal is CustomerCards::reply()'s to word (the question kept).
     *
     * @throws DomainRuleException
     */
    public function answered(CardReply $reply): void
    {
        $service = $reply->kind === self::EXTEND_TYPED ? self::serviceOf($reply->user, (int) $reply->int(0)) : null;
        if ($service === null) {
            $this->closeOnList($reply);

            return;
        }
        CustomerCards::mayChange($reply->user, $reply->admin);
        $amounts = self::amounts($reply->text);
        if ($amounts === null) {
            $this->cards->retry($reply, self::TYPE_HOW);

            return;
        }

        [$days, $gigabytes] = $amounts;
        try {
            $this->give($service, $reply->admin, $days, $gigabytes);
        } catch (ModelNotFoundException) {
            $this->closeOnList($reply);

            return;
        }
        $now = self::serviceOf($reply->user, $service->id) ?? $service;
        [$html, $rows] = $this->view($reply->user, $reply->admin, $now);
        $this->cards->done($reply, sprintf(self::EXTENDED, Messages::gift($days, Traffic::bytesOfGb($gigabytes)), $service->remote_name), $html, $rows);
    }

    /** Whether the press carries what its action needs: a page or nothing for the list, the service, a preset's amounts. */
    private static function shaped(CardPress $press): bool
    {
        $numbers = count(array_filter(array_keys($press->args), static fn(int $i): bool => $press->int($i) !== null));

        return match ($press->action) {
            self::OPEN => count($press->args) <= 1 && $numbers === count($press->args),
            self::VIEW, self::SYNC, self::EXTEND, self::EXTEND_TYPED, self::DISABLE, self::DISABLE_SET, self::ENABLE, self::DELETE, self::DELETE_SET => count($press->args) === 1 && $numbers === 1,
            self::EXTEND_ASK => count($press->args) === 3 && $numbers === 3,
            self::EXTEND_SET => count($press->args) === 4 && $press->int(0) !== null && $press->int(1) !== null && $press->int(2) !== null
                && preg_match('/^[0-9a-z]{4,12}$/', $press->args[3]) === 1,
            default => false,
        };
    }

    /** The customer's service `$id` in the shop; null when it is gone or someone else's. */
    private static function serviceOf(User $user, int $id): ?Subscription
    {
        return Subscription::query()->with(['plan', 'server'])->where('user_id', $user->id)->find($id);
    }

    /** The list's page `$page` (from 1, kept within the pages there are), with its buttons; the press answered with `$word`. */
    private function showList(CardPress $press, int $page, ?string $word = null, bool $alert = false): void
    {
        [$html, $rows] = self::list($press->user, $page);
        $this->cards->show($press, $html, $rows, $word, $alert);
    }

    /** The service's view, with its buttons; the press answered with `$word`. */
    private function showService(CardPress $press, Subscription $service, ?string $word = null, bool $alert = false): void
    {
        [$html, $rows] = $this->view($press->user, $press->admin, $service);
        $this->cards->show($press, $html, $rows, $word, $alert);
    }

    /** The service `$id` read again and its view shown — the list when it is gone —; the press answered with `$word`. */
    private function refresh(CardPress $press, int $id, string $word, bool $alert = false): void
    {
        $service = self::serviceOf($press->user, $id);
        if ($service === null) {
            $this->showList($press, 1, $word, $alert);

            return;
        }
        $this->showService($press, $service, $word, $alert);
    }

    /** A question about a service gone meanwhile: the card back on the list, the question deleted. */
    private function closeOnList(CardReply $reply): void
    {
        [$html, $rows] = self::list($reply->user, 1);
        $this->cards->done($reply, self::NOT_FOUND, $html, $rows);
    }

    /**
     * The list's words and buttons: a line and a button a service (a Persian reader's right first in each row), then
     * «⬅️ بعدی» / «➡️ قبلی» when there are more pages, and «⬅️ بازگشت».
     *
     * @return array{string, list<list<array<string, mixed>>>}
     */
    private static function list(User $user, int $page): array
    {
        $total = Subscription::query()->where('user_id', $user->id)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $services = Subscription::query()->with(['plan', 'server'])->where('user_id', $user->id)->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();

        $lines = [CustomerCards::header($user, self::SECTION), ''];
        if ($total === 0) {
            $lines[] = self::NONE;
        } else {
            $lines[] = '🛍 ' . Persian::number($total) . ' سرویس' . ($pages > 1 ? ' · صفحه ' . Persian::number($page) . ' از ' . Persian::number($pages) : '');
            foreach ($services as $service) {
                $lines[] = implode(' · ', [self::icon($service) . ' <code>' . self::name($service->remote_name) . '</code>', self::plan($service), self::name($service->server->name), Messages::expiry($service->expires_at, $service->duration_hours)]);
            }
        }

        // A button's label is plain text: the client's name as it is.
        $buttons = $services->map(static fn(Subscription $service): array => CustomerCards::button(
            self::icon($service) . ' ' . mb_substr($service->remote_name, 0, self::NAME_MAX),
            CustomerCards::data(self::PREFIX, self::VIEW, $user->id, $service->id),
        ))->values()->all();
        // Forward is leftward for a right-to-left reader: «بعدی» first, as the bot's own lists have it (Keyboard\Buttons::paged()).
        $paging = [
            ...($page < $pages ? [CustomerCards::button('⬅️ بعدی', CustomerCards::data(self::PREFIX, self::OPEN, $user->id, $page + 1))] : []),
            ...($page > 1 ? [CustomerCards::button('➡️ قبلی', CustomerCards::data(self::PREFIX, self::OPEN, $user->id, $page - 1))] : []),
        ];
        $rows = InlineKeyboard::make()->grid($buttons, 2)->row(...$paging)->row(CustomerCards::back($user->id))->build()['inline_keyboard'];

        return [implode("\n", $lines), $rows];
    }

    /**
     * A service's view: its words, then what its state allows — «🔄 همگام‌سازی», «➕ زمان و حجم», «⏸ غیرفعال» or «▶️ فعال»,
     * «🗑 حذف» — and «⬅️ سرویس‌ها». The admin's own account or an agent's gets only the way back and why.
     *
     * @return array{string, list<list<array<string, mixed>>>}
     */
    private function view(User $user, User $admin, Subscription $service): array
    {
        $toList = CustomerCards::button('⬅️ سرویس‌ها', CustomerCards::data(self::PREFIX, self::OPEN, $user->id));
        $locked = CustomerCards::lockOf($user, $admin);
        if ($locked !== null) {
            return [self::text($user, $service) . "\n\n" . $locked, InlineKeyboard::make()->row($toList)->build()['inline_keyboard']];
        }

        $allowed = $this->actions->allowed($service);
        $data = static fn(string $action): string => CustomerCards::data(self::PREFIX, $action, $user->id, $service->id);
        $buttons = array_values(array_filter([
            $allowed[SubscriptionAction::Sync->value] ? CustomerCards::button('🔄 همگام‌سازی', $data(self::SYNC)) : null,
            $allowed[SubscriptionAction::Extend->value] ? CustomerCards::button('➕ زمان و حجم', $data(self::EXTEND)) : null,
            $allowed[SubscriptionAction::Disable->value] ? CustomerCards::button('⏸ غیرفعال', $data(self::DISABLE)) : null,
            $allowed[SubscriptionAction::Enable->value] ? CustomerCards::button('▶️ فعال', $data(self::ENABLE), 'success') : null,
            $allowed[SubscriptionAction::Delete->value] ? CustomerCards::button('🗑 حذف', $data(self::DELETE), 'danger') : null,
        ]));
        $open = $allowed[SubscriptionAction::Delete->value] ? null : SubscriptionActions::openOrderOf($service);
        $html = self::text($user, $service) . ($open === null ? '' : "\n\n" . sprintf(self::OPEN_ORDER, $open));
        $rows = InlineKeyboard::make()->grid($buttons, 2)->row($toList)->build()['inline_keyboard'];

        return [$html, $rows];
    }

    /**
     * A service's words: the card's header, then its client and the customer's own name for it, plan, location, state,
     * traffic, end, devices and link, and when its panel was last read.
     */
    private static function text(User $user, Subscription $service): string
    {
        $remaining = $service->remainingBytes();
        $lines = [
            CustomerCards::header($user, self::SECTION),
            '',
            sprintf("🔖 سرویس: <code>%s</code> · اشتراک \u{200E}#%d", self::name($service->remote_name), $service->id),
            ...(($service->label ?? '') === '' ? [] : ['✏️ نام دلخواه مشتری: ' . self::name((string) $service->label)]),
            '📦 پلن: ' . self::plan($service),
            '📍 لوکیشن: ' . self::name($service->server->name),
            '🚦 وضعیت: ' . self::state($service),
            '📊 مصرف: ' . Messages::bytes($service->usedBytes()) . ' از ' . Messages::traffic($service->traffic_limit_bytes) . ($remaining === null ? '' : ' · مانده: ' . Messages::bytes($remaining)),
            '📅 پایان: ' . Messages::expiry($service->expires_at, $service->duration_hours),
            '👥 تعداد کاربر: ' . Messages::devices($service->ip_limit),
            '🔗 لینک: <code>' . htmlspecialchars($service->subscription_url) . '</code>',
        ];
        if ($service->last_synced_at !== null) {
            $lines[] = '🕘 آخرین خواندن از پنل: ' . Persian::date($service->last_synced_at, withTime: true);
        }

        return implode("\n", $lines);
    }

    /** The icon of where a service stands: ⚫️ gone from its panel, ⏸ switched off, 🔴 ended, 🟢 running. */
    private static function icon(Subscription $service): string
    {
        return match (true) {
            $service->status === SubscriptionStatus::Deleted => '⚫️',
            $service->status === SubscriptionStatus::Disabled => '⏸',
            self::ended($service) => '🔴',
            default => '🟢',
        };
    }

    /** Where a service stands, in words after its icon. */
    private static function state(Subscription $service): string
    {
        return self::icon($service) . ' ' . match (true) {
            $service->status === SubscriptionStatus::Deleted => 'کلاینتش دیگر روی پنل نیست',
            $service->status === SubscriptionStatus::Disabled => 'غیرفعال شده',
            $service->isExpiredByTraffic() => 'حجمش تمام شده',
            self::ended($service) => 'منقضی شده',
            default => 'فعال',
        };
    }

    /** Ended: by its time or traffic, as its panel said or as its numbers already show before a sync says it. */
    private static function ended(Subscription $service): bool
    {
        return $service->isOver(Carbon::now()) || $service->isExpiredByTraffic();
    }

    /** The plan the service was sold or renewed on, «اکانت تست» for a free test, «—» for none (one claimed by its link). */
    private static function plan(Subscription $service): string
    {
        return $service->is_test ? 'اکانت تست' : self::name($service->plan->name ?? '—');
    }

    /**
     * «🔄 همگام‌سازی»: the client read again from its panel; one the panel no longer has is marked gone.
     *
     * @throws DomainRuleException
     */
    private function sync(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        $this->actions->sync($service, Actor::groupAdmin($press->admin));
        $now = self::serviceOf($press->user, $service->id);
        if ($now === null) {
            $this->showList($press, 1, self::NOT_FOUND, alert: true);

            return;
        }
        $this->showService($press, $now, $now->status === SubscriptionStatus::Deleted ? self::GONE_FROM_PANEL : self::SYNCED);
    }

    /**
     * «➕ زمان و حجم»: the presets the service can take — days for one that ends, traffic for one with a quota —,
     * «✍️ مقدار دلخواه» and «⬅️ بازگشت» to its view.
     *
     * @throws DomainRuleException
     */
    private function showExtend(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        if (!$this->offered($service, SubscriptionAction::Extend)) {
            $this->showService($press, $service, self::STALE, alert: true);

            return;
        }

        $user = $press->user;
        $presets = [];
        foreach (self::PRESETS as [$label, $days, $gigabytes]) {
            if (($days === 0 || $service->hasTerm()) && ($gigabytes === 0 || $service->traffic_limit_bytes > 0)) {
                $presets[] = CustomerCards::button($label, CustomerCards::data(self::PREFIX, self::EXTEND_ASK, $user->id, $service->id, $days, $gigabytes));
            }
        }
        $rows = InlineKeyboard::make()
            ->grid($presets, 2)
            ->row(CustomerCards::button('✍️ مقدار دلخواه', CustomerCards::data(self::PREFIX, self::EXTEND_TYPED, $user->id, $service->id)))
            ->row(CustomerCards::button('⬅️ بازگشت', CustomerCards::data(self::PREFIX, self::VIEW, $user->id, $service->id)))
            ->build()['inline_keyboard'];

        $this->cards->show($press, self::text($user, $service) . "\n\n" . self::HOW_MUCH, $rows);
    }

    /**
     * A preset asked again: «✔️ بله» — its nonce drawn now — gives it, «⬅️ انصراف» goes back to the presets.
     *
     * @throws DomainRuleException
     */
    private function askExtend(CardPress $press, Subscription $service, int $days, int $gigabytes): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        if (!$this->offered($service, SubscriptionAction::Extend)) {
            $this->showService($press, $service, self::STALE, alert: true);

            return;
        }

        $question = sprintf(self::ASK_EXTEND, Messages::gift($days, Traffic::bytesOfGb($gigabytes)), self::name($service->remote_name));
        $nonce = $this->cards->nonce($press, self::PREFIX, $question);
        $this->confirm($press, $service, $question, self::EXTEND, CustomerCards::data(self::PREFIX, self::EXTEND_SET, $press->user->id, $service->id, $days, $gigabytes, $nonce), 'success');
    }

    /**
     * «✔️ بله» on a preset: given once for its nonce, the customer told; pressed again, said so.
     *
     * @throws DomainRuleException
     */
    private function extend(CardPress $press, Subscription $service, int $days, int $gigabytes, string $nonce): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        $this->cards->once($press, self::PREFIX, $nonce, fn() => $this->give($service, $press->admin, $days, $gigabytes));
        $this->refresh($press, $service->id, sprintf(self::EXTENDED, Messages::gift($days, Traffic::bytesOfGb($gigabytes)), $service->remote_name));
    }

    /**
     * «✍️ مقدار دلخواه»: the bot's question under the card; the admin's reply comes back to answered().
     *
     * @throws DomainRuleException
     */
    private function askAmount(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        if (!$this->offered($service, SubscriptionAction::Extend)) {
            $this->showService($press, $service, self::STALE, alert: true);

            return;
        }

        $this->cards->ask($press, self::PREFIX, self::EXTEND_TYPED, [$service->id], sprintf(self::TYPE_AMOUNT, $service->remote_name), self::TYPE_PLACEHOLDER);
    }

    /**
     * `$days` and `$gigabytes` on top of what the service has, the customer told.
     *
     * @throws DomainRuleException|ModelNotFoundException
     */
    private function give(Subscription $service, User $admin, int $days, int $gigabytes): void
    {
        $this->actions->extend($service, Actor::groupAdmin($admin), ['days' => $days, 'traffic_gb' => $gigabytes, 'notify' => true]);
    }

    /**
     * «⏸ غیرفعال» asked again.
     *
     * @throws DomainRuleException
     */
    private function askSwitchOff(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        if (!$this->offered($service, SubscriptionAction::Disable)) {
            $this->showService($press, $service, self::STALE, alert: true);

            return;
        }

        $question = sprintf(self::ASK_DISABLE, self::name($service->remote_name));
        $this->confirm($press, $service, $question, self::VIEW, CustomerCards::data(self::PREFIX, self::DISABLE_SET, $press->user->id, $service->id), 'danger');
    }

    /** @throws DomainRuleException */
    private function disable(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        $this->actions->disable($service, Actor::groupAdmin($press->admin), null);
        $this->refresh($press, $service->id, self::DISABLED);
    }

    /** @throws DomainRuleException */
    private function enable(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        $this->actions->enable($service, Actor::groupAdmin($press->admin));
        $this->refresh($press, $service->id, self::ENABLED);
    }

    /**
     * «🗑 حذف» asked again: its client goes off its panel too, for good.
     *
     * @throws DomainRuleException
     */
    private function askDelete(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        if (!$this->offered($service, SubscriptionAction::Delete)) {
            $this->showService($press, $service, self::STALE, alert: true);

            return;
        }

        $question = sprintf(self::ASK_DELETE, self::name($service->remote_name)) . ($service->isActive() ? self::CUSTOMER_TOLD : '');
        $this->confirm($press, $service, $question, self::VIEW, CustomerCards::data(self::PREFIX, self::DELETE_SET, $press->user->id, $service->id), 'danger');
    }

    /**
     * «✔️ بله» on the delete: the client off its panel and the service gone; the card back on the list.
     *
     * @throws DomainRuleException|ModelNotFoundException
     */
    private function delete(CardPress $press, Subscription $service): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        $this->actions->delete($service, Actor::groupAdmin($press->admin), null, false);
        $this->refresh($press, $service->id, sprintf(self::DELETED, $service->remote_name));
    }

    /** A change asked again: the service's words with `$question`, «⬅️ انصراف» (to `$cancel`) and «✔️ بله» on the right. */
    private function confirm(CardPress $press, Subscription $service, string $question, string $cancel, string $yes, string $style): void
    {
        $rows = InlineKeyboard::make()->row(
            CustomerCards::button('⬅️ انصراف', CustomerCards::data(self::PREFIX, $cancel, $press->user->id, $service->id)),
            CustomerCards::button('✔️ بله', $yes, $style),
        )->build()['inline_keyboard'];

        $this->cards->show($press, self::text($press->user, $service) . "\n\n" . $question, $rows);
    }

    /** Whether the service's state allows `$action` now (a card older than the service offers what it no longer does). */
    private function offered(Subscription $service, SubscriptionAction $action): bool
    {
        return $this->actions->allowed($service)[$action->value];
    }

    /**
     * Days and gigabytes as typed — «30 10», «۳۰ ۰» — two whole numbers in any digits; null for anything else.
     *
     * @return array{int, int}|null
     */
    private static function amounts(string $typed): ?array
    {
        $parts = preg_split('/[\s\h]+/u', trim($typed));
        if ($parts === false || count($parts) !== 2) {
            return null;
        }
        $days = CustomerCards::number($parts[0]);
        $gigabytes = CustomerCards::number($parts[1]);

        return $days === null || $gigabytes === null ? null : [$days, $gigabytes];
    }

    private static function name(string $name): string
    {
        return htmlspecialchars(mb_substr(trim($name), 0, self::NAME_MAX));
    }
}
