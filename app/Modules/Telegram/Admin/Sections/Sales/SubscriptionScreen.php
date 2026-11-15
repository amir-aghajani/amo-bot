<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Messages;

/**
 * One service (the panel's service dialog, GET /subscriptions/{id}): its link — tapped, copied —, its facts as of the
 * panel's last answer (usage, end, the shop's own removal, term, renewal, devices, moments), and every operation its
 * state allows (`actions`): «🔄 به‌روزرسانی از پنل», «🎁 افزایش زمان و حجم» (the grant; ExtendScreen), switch it off
 * (asked first, a note for the customer or none) or on, move it to another server (a server picked, asked first; a
 * previous server that does not answer asks whether to move without it), hand it to another customer (the grant: their
 * Telegram id, whom it names shown before anything is sent) and delete it (the grant: asked first — a claimed one may
 * leave the shop alone, its client kept; a panel that does not answer asks whether to delete without it).
 *
 * Actions (each with the service's id): `sy`, `en`, `di` (asks) → `dig` | `din`; `mv` (servers) → `mvs` (server: asks)
 * → `mvg` (server) → `mvl` (server: without the previous one); `tr` (asks the id) → `trg` (Telegram id); `de` (asks)
 * → `deg` | `den` | `dek` (the client kept) → `dex` (without its panel).
 */
final class SubscriptionScreen extends BaseScreen
{
    public const ID = 'sbv';

    /** What moving a service does (the panel's move dialog). */
    private const MOVING = 'سرویس با زمان و حجم باقی‌مانده‌اش روی سرور مقصد ساخته و از سرور قبلی حذف می‌شود؛ لینکش عوض می‌شود و لینک جدید در تلگرام یا با ایمیل برای مشتری می‌رود. اگر سرور قبلی جواب ندهد، می‌پرسیم بدون حذف از آن ادامه دهیم یا انتقال لغو شود.';

    /** A server's own screen, in the owner's management (the Owner section's): the service's server opens it. */
    private const SERVER = 'svv';

    public function show(Admin $admin, array $args): View
    {
        CustomerRowsScreen::opened($admin, self::ID, self::id($args), $args);

        return $this->view($admin, self::service($admin, self::id($args)));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'sy':
                $service = self::row($admin->api->post("/subscriptions/{$id}/sync"));
                if (($service['status'] ?? '') === 'deleted') {
                    $admin->alert('کلاینت ' . self::name($service) . ' روی پنل پیدا نشد؛ اشتراک حذف‌شده علامت خورد.');
                } else {
                    $admin->notice('🔄 سرویس ' . self::name($service) . ' از پنل به‌روز شد');
                }

                return $this->view($admin, $service);
            case 'en':
                $service = self::row($admin->api->post("/subscriptions/{$id}/enable"));
                $admin->notice('✅ سرویس ' . self::name($service) . ' دوباره فعال شد');

                return $this->view($admin, $service);
            case 'di':
                $service = self::service($admin, $id);

                return Sales::choose(
                    'سرویس ' . self::name($service) . ' غیرفعال شود؟',
                    'کلاینت روی پنل خاموش می‌شود و تا فعال شدن دوباره وصل نمی‌شود. مشتری در تلگرام یا با ایمیل خبردار می‌شود.',
                    Button::to(Sales::WITH_NOTE, self::ID, 'din', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'dig', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                );
            case 'dig':
                return $this->disabled($admin, $id, null);
            case 'din':
                return $admin->ask(new Prompt('توضیح برای مشتری', self::ID, 'din', [$id], 'مثلا: استفاده خارج از قوانین. ' . Sales::NOTE_HINT));
            case 'mv':
                return $this->servers($admin, self::service($admin, $id));
            case 'mvs':
                $service = self::service($admin, $id);
                $target = Sales::serverName($admin, self::id($args, 1));

                return Sales::choose(
                    'سرویس ' . self::name($service) . ' به «' . $target . '» منتقل شود؟',
                    Fmt::e(self::MOVING),
                    Button::to('🔀 انتقال', self::ID, 'mvg', $id, self::id($args, 1)),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                );
            case 'mvg':
            case 'mvl':
                return $this->moved($admin, $id, self::id($args, 1), $action === 'mvl');
            case 'tr':
                return $admin->ask(new Prompt('شناسه عددی تلگرام مشتری جدید', self::ID, 'tr', [$id], 'مشتری باید یک بار ربات این فروشگاه را استارت کرده باشد.'));
            case 'trg':
                $service = self::row($admin->api->post("/subscriptions/{$id}/transfer", ['telegram_id' => self::id($args, 1)]));
                $admin->notice('📤 سرویس ' . self::name($service) . ' به ' . Sales::customer(Json::part($service, 'user')) . ' منتقل شد');

                return $this->view($admin, $service);
            case 'de':
                return $this->deleting($admin, self::service($admin, $id));
            case 'deg':
                return $this->deleted($admin, $id, null, false);
            case 'den':
                return $admin->ask(new Prompt('توضیح برای مشتری', self::ID, 'den', [$id], 'اختیاری؛ فقط برای سرویسی که هنوز فعال است فرستاده می‌شود — حداکثر ۳۰۰ نویسه.'));
            case 'dek':
                return $this->deleted($admin, $id, null, true);
            case 'dex':
                $note = $admin->state(self::ID)['note'] ?? null;

                return $this->deleted($admin, $id, is_string($note) ? $note : null, true, panelDown: true);
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);

        return match ($action) {
            'din' => $this->disabled($admin, $id, $answer->text()),
            'den' => $this->deleted($admin, $id, $answer->text(), false),
            'tr' => $this->recipient($admin, $id, preg_replace('/\s+/u', '', $answer->latin()) ?? ''),
            default => $this->show($admin, $args),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function service(Admin $admin, int $id): array
    {
        return self::row($admin->api->get("/subscriptions/{$id}"));
    }

    /**
     * The service an answer carries.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function row(array $answer): array
    {
        return Json::part($answer, 'subscription');
    }

    /**
     * The client's name on its panel, isolated in a Persian line.
     *
     * @param array<string, mixed> $service
     */
    private static function name(array $service): string
    {
        return "\u{2068}" . (string) ($service['name'] ?? '') . "\u{2069}";
    }

    private function disabled(Admin $admin, int $id, ?string $note): View
    {
        $service = self::row($admin->api->post("/subscriptions/{$id}/disable", $note === null ? null : ['note' => $note]));
        $admin->notice('⏸ سرویس ' . self::name($service) . ' غیرفعال شد');

        return $this->view($admin, $service);
    }

    /**
     * «🔀 انتقال به سرور دیگر»: the servers it may go to — those that cannot take it now listed with the server's reason.
     *
     * @param array<string, mixed> $service
     */
    private function servers(Admin $admin, array $service): View
    {
        $id = (int) ($service['id'] ?? 0);
        $current = (int) (Json::part($service, 'server')['id'] ?? 0);
        $view = View::make('🔀 انتقال به سرور دیگر', 'مدیریت', 'فروش', 'اشتراک‌ها', self::name($service))->line(Fmt::e(self::MOVING));
        $choices = [];
        foreach (Sales::servers($admin) as $server) {
            if ($server['id'] === $current) {
                continue;
            }
            if ($server['reason'] !== null) {
                $view->line('⛔️ ' . Fmt::e($server['name']) . ': ' . Fmt::e($server['reason']));

                continue;
            }
            $choices[] = Button::to('🖥 ' . $server['name'] . ($server['active'] !== null ? ' (' . Fmt::number($server['active']) . ' سرویس فعال)' : ''), self::ID, 'mvs', $id, $server['id']);
        }
        if ($choices === []) {
            $view->line()->line('سروری نیست که این سرویس الان به آن منتقل شود.');
        }

        return $view->grid(1, ...$choices)->back(self::ID, 'show', $id);
    }

    /**
     * Move the service to `$server` — without its previous server when `$leave` —: the service as it then is; a previous
     * server that does not answer asks whether to go on without it (its client left there until it answers, then removed
     * by itself).
     */
    private function moved(Admin $admin, int $id, int $server, bool $leave): View
    {
        try {
            $service = self::row($admin->api->post("/subscriptions/{$id}/move", ['server_id' => $server, 'leave_previous' => $leave]));
        } catch (ApiRefusal $refusal) {
            $previous = $refusal->field('previous');
            if ($previous === null || $leave) {
                throw $refusal;
            }
            $service = self::service($admin, $id);
            $from = (string) (Json::part($service, 'server')['name'] ?? '');

            return Sales::choose(
                'سرور قبلی جواب نداد',
                Fmt::e($previous) . "\n" . Fmt::e('بدون حذف از «' . $from . '» منتقل شود؟ کلاینت قبلی روی آن سرور می‌ماند تا سرور دوباره جواب بدهد، بعد خودکار حذف می‌شود.'),
                Button::to('🔀 انتقال بدون حذف از سرور قبلی', self::ID, 'mvl', $id, $server),
                Button::to('✖️ انصراف', self::ID, 'show', $id),
            );
        }
        $to = (string) (Json::part($service, 'server')['name'] ?? '');
        $admin->notice('🔀 سرویس ' . self::name($service) . ' به «' . $to . '» منتقل شد' . ($leave ? '؛ کلاینت قبلی روی سرور قبلی ماند' : ''));

        return $this->view($admin, $service);
    }

    /**
     * Whom the typed Telegram id names — the server's own look-up, refusing as the transfer would (its words over the
     * question, which still waits) —, before anything is sent: asked to confirm.
     */
    private function recipient(Admin $admin, int $id, string $telegram): View
    {
        $recipient = Json::part($admin->api->get("/subscriptions/{$id}/transfer", ['telegram_id' => $telegram]), 'recipient');
        $service = self::service($admin, $id);
        $to = (int) ($recipient['telegram_id'] ?? 0);
        $facts = [];
        foreach (Sales::customerFacts($recipient) as $label => $value) {
            if ($value !== null) {
                $facts[] = Fmt::e($label) . ': ' . $value;
            }
        }

        return Sales::choose(
            'سرویس ' . self::name($service) . ' به ' . Sales::customer($recipient) . ' منتقل شود؟',
            implode("\n", $facts) . "\n\n" . Fmt::e('سرویس با همین لینک، زمان و حجم به حساب مشتری دیگری می‌رود و «تمدید خودکار» آن خاموش می‌شود. هر دو مشتری در تلگرام یا با ایمیل خبردار می‌شوند. دستگاه‌های مشتری قبلی تا وقتی مشتری جدید «تغییر لینک» را نزند وصل می‌مانند.'),
            Button::to('📤 انتقال به مشتری دیگر', self::ID, 'trg', $id, $to),
            Button::to('✖️ انصراف', self::ID, 'show', $id),
        );
    }

    /**
     * «🗑 حذف سرویس», asked first in the panel's words — a service gone from its panel leaves the shop alone, nobody told;
     * a claimed one, in the main shop, may leave the shop alone with its client kept on its panel.
     *
     * @param array<string, mixed> $service
     */
    private function deleting(Admin $admin, array $service): View
    {
        $id = (int) ($service['id'] ?? 0);
        $gone = ($service['status'] ?? '') === 'deleted';
        $keeps = Json::text($service, 'claimed_at') !== null && $admin->seat->isOwner() && $admin->seat->shop()->isMain();
        $detail = $gone
            ? 'کلاینت این سرویس دیگر روی پنل نیست؛ سرویس از فروشگاه کامل حذف می‌شود و این کار برگشت ندارد. به مشتری چیزی گفته نمی‌شود.'
            : 'کلاینت از پنل و سرویس از فروشگاه کامل حذف می‌شود و لینکش دیگر کار نمی‌کند؛ این کار برگشت ندارد. اگر سرویس فعال باشد، مشتری در تلگرام یا با ایمیل خبردار می‌شود.';
        if ($keeps) {
            $detail .= "\n\n" . 'اگر این سرویس به دست مشتری اشتباهی رسیده، فقط از فروشگاه حذفش کنید: کلاینت روی پنل می‌ماند و صاحبش می‌تواند با فرستادن لینک دوباره اضافه‌اش کند.';
        }

        return Sales::choose(
            'سرویس ' . self::name($service) . ' حذف شود؟',
            Fmt::e($detail),
            $gone ? null : Button::to(Sales::WITH_NOTE, self::ID, 'den', $id),
            Button::to($gone ? '🗑 بله، حذف شود' : Sales::WITHOUT_NOTE, self::ID, 'deg', $id),
            $keeps ? Button::to('🗑 فقط از فروشگاه حذف شود (کلاینت روی پنل می‌ماند)', self::ID, 'dek', $id) : null,
            Button::to('✖️ انصراف', self::ID, 'show', $id),
        );
    }

    /**
     * Delete the service — without its panel when `$leave` —, then its list; a panel that does not answer asks whether
     * to delete it from the shop alone (the note kept for that).
     */
    private function deleted(Admin $admin, int $id, ?string $note, bool $leave, bool $panelDown = false): View
    {
        $service = self::service($admin, $id);
        $server = (string) (Json::part($service, 'server')['name'] ?? '');
        $agentShop = !$admin->seat->shop()->isMain();
        try {
            $admin->api->post("/subscriptions/{$id}/delete", ['note' => $note ?? '', 'leave_panel' => $leave]);
        } catch (ApiRefusal $refusal) {
            $panel = $refusal->field('panel');
            if ($panel === null || $leave) {
                throw $refusal;
            }
            $admin->remember(self::ID, ['note' => $note]);

            return Sales::choose(
                'پنل جواب نداد',
                Fmt::e($panel) . "\n" . Fmt::e('سرویس بدون حذف از سرور، فقط در فروشگاه حذف شود؟ کلاینتش روی «' . $server . '» می‌ماند و اگر سرور دوباره در دسترس باشد، تا ' . ($agentShop ? 'پشتیبانی حذفش نکند' : 'خودتان حذفش نکنید') . ' کار می‌کند.'),
                Button::to('🗑 بله، فقط از فروشگاه حذف شود', self::ID, 'dex', $id),
                Button::to('✖️ انصراف', self::ID, 'show', $id),
            );
        }
        $list = CustomerRowsScreen::back($admin, self::ID, $id, SubscriptionsScreen::ID);
        $admin->forget(self::ID);
        $admin->notice(match (true) {
            $leave && !$panelDown => '🗑 سرویس ' . self::name($service) . ' فقط از فروشگاه حذف شد؛ کلاینتش روی سرور «' . $server . '» ماند تا صاحبش با لینکش اضافه‌اش کند',
            $leave => '🗑 سرویس ' . self::name($service) . ' حذف شد؛ کلاینتش روی سرور «' . $server . '» ماند و ' . ($agentShop ? 'حذفش از سرور با پشتیبانی است' : 'باید خودتان حذفش کنید'),
            default => '🗑 سرویس ' . self::name($service) . ' حذف شد',
        });

        return $admin->open($list);
    }

    /**
     * When a service ends, as the bot words it: its date and what is left, its term while it waits for the first
     * connection, never — or, deleted, the date on record.
     *
     * @param array<string, mixed> $service
     */
    private static function expiry(array $service): string
    {
        $expires = Json::text($service, 'expires_at');
        $hours = (int) ($service['duration_hours'] ?? 0);
        if (($service['status'] ?? '') === 'deleted') {
            return $expires === null ? Fmt::NONE : Fmt::date($expires);
        }
        if ($expires === null) {
            return $hours > 0 ? 'در انتظار اولین اتصال · ' . Fmt::hours($hours) . ' از اولین اتصال' : 'نامحدود';
        }

        return Fmt::e(Messages::expiry(Fmt::moment($expires), $hours)) . ((bool) ($service['expiring_soon'] ?? false) ? ' ⏳' : '');
    }

    /**
     * Traffic used against the quota: the amounts, the share and a bar — or unlimited.
     *
     * @param array<string, mixed> $traffic
     */
    private static function usage(array $traffic): string
    {
        $used = (int) ($traffic['used'] ?? 0);
        $limit = (int) ($traffic['limit'] ?? 0);
        if ($limit <= 0) {
            return Fmt::bytes($used) . ' · حجم نامحدود';
        }
        $filled = min(10, intdiv($used * 10, $limit));

        return Fmt::bytes($used) . ' از ' . Fmt::bytes($limit) . ' (' . Messages::percent($used, $limit) . ')' . "\n" . str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled);
    }

    /** @param array<string, mixed> $service */
    private function view(Admin $admin, array $service): View
    {
        $id = (int) ($service['id'] ?? 0);
        $status = (string) ($service['status'] ?? '');
        $plan = Json::text(Json::part($service, 'plan'), 'name');
        $claimed = Json::text($service, 'claimed_at') !== null;
        $next = Json::part($service, 'next_period');
        $devices = Json::part($service, 'next_devices');
        $removes = Json::text($service, 'removes_at');
        $removal = $removes === null ? null : Fmt::moment($removes);
        $hours = (int) ($service['duration_hours'] ?? 0);
        $test = Json::on($service, 'is_test');
        $link = (string) ($service['link'] ?? '');
        $dead = $status === 'deleted';

        $view = View::make('📡 ' . (string) ($service['name'] ?? ''), 'مدیریت', 'فروش', 'اشتراک‌ها')
            ->line('اشتراک ' . Fmt::id($id))
            ->line('🔗 لینک اشتراک' . ($dead ? '' : ' (لمس کنید تا کپی شود)') . ':')
            ->line($dead ? Fmt::ltr($link) . "\n" . 'این لینک دیگر کار نمی‌کند.' : Fmt::code($link))
            ->line()
            ->facts([
                'وضعیت' => Fmt::e(Sales::word(Sales::SUBSCRIPTION_STATUS, $status)) . ($status === 'disabled' && Json::text($service, 'disabled_at') !== null ? ' · از ' . Fmt::date(Json::text($service, 'disabled_at'), true) : ''),
            ])
            ->facts(Sales::customerFacts(Json::part($service, 'user')))
            ->facts([
                'پلن' => $test ? 'اکانت تست رایگان' : ($plan !== null ? Fmt::e($plan) : ($claimed ? 'بدون پلن (مشتری هنگام تمدید یکی از پلن‌هایی را که روی سرور این سرویس فروخته می‌شوند انتخاب می‌کند)' : 'سرویس دلخواه')),
                'سرور' => Fmt::e((string) (Json::part($service, 'server')['name'] ?? '')),
                'مصرف' => self::usage(Json::part($service, 'traffic')),
                'پایان' => self::expiry($service),
                'حذف خودکار' => $removes === null ? null : Fmt::date($removes, true) . ' (' . ($removal !== null && $removal->isFuture() ? Sales::left($removes) : 'در نوبت حذف') . ')',
                'مدت' => Fmt::hours($hours),
                'تمدید خودکار' => $hours > 0 && !$test ? (Json::on($service, 'auto_renew') ? 'روشن' : 'خاموش') : null,
                'دوره بعدی' => $next === [] ? null : 'از ' . Fmt::date(Json::text($next, 'starts_at')) . '، حداکثر ' . Fmt::bytes((int) ($next['traffic'] ?? 0)) . ' (تمدید شده؛ حجم مصرف‌نشده دوره فعلی تا آن روز قابل استفاده است)',
                'کاربر هم‌زمان' => Messages::devices((int) ($service['ip_limit'] ?? 0)) . ($devices === [] ? '' : ' · از ' . Fmt::date(Json::text($devices, 'from')) . ': ' . Messages::devices((int) ($devices['devices'] ?? 0))),
                'اولین اتصال' => Sales::when($service, 'starts_at'),
                ($claimed ? 'افزودن با لینک' : 'خرید') => Sales::when($service, $claimed ? 'claimed_at' : 'created_at'),
                'آخرین پرسش از پنل' => Sales::when($service, 'last_synced_at') ?? 'هنوز نه',
            ]);

        $actions = Json::part($service, 'actions');
        $seat = $admin->seat;
        $view->row(
            Json::on($actions, 'sync') ? Button::to('🔄 به‌روزرسانی از پنل', self::ID, 'sy', $id) : null,
            Json::on($actions, 'extend') && $seat->holds(StaffGrant::Extend) ? Button::to('🎁 افزایش زمان و حجم', ExtendScreen::ID, 'new', $id) : null,
        );
        $view->row(
            Json::on($actions, 'enable') ? Button::to('▶️ فعال کردن', self::ID, 'en', $id) : null,
            Json::on($actions, 'disable') ? Button::to('⏸ غیرفعال کردن', self::ID, 'di', $id) : null,
            Json::on($actions, 'move') ? Button::to('🔀 انتقال به سرور دیگر', self::ID, 'mv', $id) : null,
        );
        $view->row(
            Json::on($actions, 'transfer') && $seat->holds(StaffGrant::Delete) ? Button::to('📤 انتقال به مشتری دیگر', self::ID, 'tr', $id) : null,
            Json::on($actions, 'delete') && $seat->holds(StaffGrant::Delete) ? Button::to('🗑 حذف سرویس', self::ID, 'de', $id) : null,
        );
        $view->row(...Sales::customerButtons(Json::part($service, 'user')));
        $server = Json::part($service, 'server');
        if ($admin->opens(self::SERVER)) {
            $view->row(Button::to('🖥 سرور ' . (string) ($server['name'] ?? ''), self::SERVER, 'show', (int) ($server['id'] ?? 0)));
        }

        return $view->back(CustomerRowsScreen::back($admin, self::ID, $id, SubscriptionsScreen::ID));
    }
}
