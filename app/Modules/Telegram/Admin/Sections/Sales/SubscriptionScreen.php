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
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;

/**
 * One service (the panel's service dialog, GET /subscriptions/{id}): its summary card as of the panel's last answer
 * (who, plan, server, devices, renewal, moments), its traffic and its time each with a bar of what is used, its link —
 * tapped or «📋 کپی لینک», copied —, and every operation its state allows (`actions`): «📥 به‌روزرسانی از پنل»,
 * «🎁 افزایش زمان و حجم» (the grant; ExtendScreen), switch it off (asked first, a note for the customer or none) or on,
 * move it to another server (a server picked, asked first; a previous server that does not answer asks whether to move
 * without it), hand it to another customer (the grant: their Telegram id, whom it names shown before anything is sent)
 * and delete it (the grant: asked first — a claimed one may leave the shop alone, its client kept; a panel that does not
 * answer asks whether to delete without it).
 *
 * Actions (each with the service's id): `sy`, `en`, `di` (asks) → `dig` | `din`; `mv` (servers) → `mvs` (server: asks)
 * → `mvg` (server) → `mvl` (server: without the previous one); `tr` (asks the id) → `trg` (Telegram id); `de` (asks)
 * → `deg` | `den` | `dek` (the client kept) → `dex` (without its panel).
 */
final class SubscriptionScreen extends BaseScreen
{
    public const ID = 'sbv';

    /** The way to move a service, and the picker's title. */
    public const MOVE = Sales::MOVE . ' انتقال به سرور دیگر';

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
                    $admin->notice(Sales::SYNC . ' سرویس ' . self::name($service) . ' از پنل به‌روز شد');
                }

                return $this->view($admin, $service);
            case 'en':
                $service = self::row($admin->api->post("/subscriptions/{$id}/enable"));
                $admin->notice('✅ سرویس ' . self::name($service) . ' دوباره فعال شد');

                return $this->view($admin, $service);
            case 'di':
                $service = self::service($admin, $id);

                return Sales::choose(
                    $admin,
                    'سرویس ' . self::name($service) . ' غیرفعال شود؟',
                    'کلاینت روی پنل خاموش می‌شود و تا فعال شدن دوباره وصل نمی‌شود. مشتری در تلگرام یا با ایمیل خبردار می‌شود.',
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'din', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'dig', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                );
            case 'dig':
                return $this->disabled($admin, $id, null);
            case 'din':
                return $admin->ask((new Prompt('توضیح برای مشتری', self::ID, 'din', [$id], 'مشتری این متن را با خبر غیرفعال شدن سرویسش می‌بیند.'))
                    ->example('استفاده خارج از قوانین')
                    ->limits(Sales::NOTE_LIMIT));
            case 'mv':
                return $this->servers($admin, self::service($admin, $id));
            case 'mvs':
                $service = self::service($admin, $id);
                $target = Sales::serverName($admin, self::id($args, 1));

                return Sales::choose(
                    $admin,
                    'سرویس ' . self::name($service) . ' به «' . $target . '» منتقل شود؟',
                    Fmt::e(self::MOVING),
                    false,
                    Button::to(Words::yes('منتقل شود'), self::ID, 'mvg', $id, self::id($args, 1)),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                );
            case 'mvg':
            case 'mvl':
                return $this->moved($admin, $id, self::id($args, 1), $action === 'mvl');
            case 'tr':
                return $admin->ask((new Prompt('شناسه عددی تلگرام مشتری جدید', self::ID, 'tr', [$id], 'مشتری باید یک بار ربات این فروشگاه را استارت کرده باشد؛ پیش از انتقال، نامش را می‌بینید.'))
                    ->example('123456789')
                    ->limits('فقط عدد'));
            case 'trg':
                $service = self::row($admin->api->post("/subscriptions/{$id}/transfer", ['telegram_id' => self::id($args, 1)]));
                $admin->notice(Sales::HAND_OVER . ' سرویس ' . self::name($service) . ' به ' . Sales::customer(Json::part($service, 'user')) . ' منتقل شد');

                return $this->view($admin, $service);
            case 'de':
                return $this->deleting($admin, self::service($admin, $id));
            case 'deg':
                return $this->deleted($admin, $id, null, false);
            case 'den':
                return $admin->ask((new Prompt('توضیح برای مشتری', self::ID, 'den', [$id], 'فقط برای سرویسی که هنوز فعال است فرستاده می‌شود.'))
                    ->example('درخواست خود مشتری')
                    ->limits(Sales::NOTE_LIMIT));
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
        $server = Json::part($service, 'server');
        [$choices, $closed] = Sales::targets($admin, [(int) ($server['id'] ?? 0)], self::ID, 'mvs', [$id]);
        $view = View::make(self::MOVE, ...Sales::under(Sales::SERVICES, self::name($service)))
            ->card(['سرور فعلی' => Fmt::e((string) ($server['name'] ?? ''))]);
        if ($closed !== []) {
            $view->section('⛔️ سرورهایی که الان سرویس نمی‌گیرند')->lines(...$closed);
        }
        if ($choices === []) {
            $view->line()->line('سروری نیست که این سرویس الان به آن منتقل شود.');
        }

        return $view->grid(1, ...$choices)->help(Fmt::e(self::MOVING))->back(self::ID, 'show', $id);
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
                $admin,
                'سرور قبلی جواب نداد',
                '⚠️ ' . Fmt::e($previous) . "\n" . Fmt::e('بدون حذف از «' . $from . '» منتقل شود؟ کلاینت قبلی روی آن سرور می‌ماند تا سرور دوباره جواب بدهد، بعد خودکار حذف می‌شود.'),
                false,
                Button::to(Sales::MOVE . ' انتقال بدون حذف از سرور قبلی', self::ID, 'mvl', $id, $server),
                Button::to(Words::CANCEL, self::ID, 'show', $id),
            );
        }
        $to = (string) (Json::part($service, 'server')['name'] ?? '');
        $admin->notice(Sales::MOVE . ' سرویس ' . self::name($service) . ' به «' . $to . '» منتقل شد' . ($leave ? '؛ کلاینت قبلی روی سرور قبلی ماند' : ''));

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

        return Sales::choose(
            $admin,
            'سرویس ' . self::name($service) . ' به ' . Sales::customer($recipient) . ' منتقل شود؟',
            Sales::quote(Sales::customerFacts($recipient)) . "\n" . Fmt::e('سرویس با همین لینک، زمان و حجم به حساب مشتری دیگری می‌رود و «تمدید خودکار» آن خاموش می‌شود. هر دو مشتری در تلگرام یا با ایمیل خبردار می‌شوند. دستگاه‌های مشتری قبلی تا وقتی مشتری جدید «تغییر لینک» را نزند وصل می‌مانند.'),
            false,
            Button::to(Words::yes('به این مشتری منتقل شود'), self::ID, 'trg', $id, $to),
            Button::to(Words::CANCEL, self::ID, 'show', $id),
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
            $admin,
            'سرویس ' . self::name($service) . ' حذف شود؟',
            Fmt::e($detail),
            true,
            $gone ? null : Button::to(Sales::WITH_NOTE, self::ID, 'den', $id),
            Button::to($gone ? Words::yes('حذف شود') : Sales::WITHOUT_NOTE, self::ID, 'deg', $id),
            $keeps ? Button::to('🗑 فقط از فروشگاه حذف شود (کلاینت روی پنل می‌ماند)', self::ID, 'dek', $id) : null,
            Button::to(Words::CANCEL, self::ID, 'show', $id),
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
                $admin,
                'پنل جواب نداد',
                '⚠️ ' . Fmt::e($panel) . "\n" . Fmt::e('سرویس بدون حذف از سرور، فقط در فروشگاه حذف شود؟ کلاینتش روی «' . $server . '» می‌ماند و اگر سرور دوباره در دسترس باشد، تا ' . ($agentShop ? 'پشتیبانی حذفش نکند' : 'خودتان حذفش نکنید') . ' کار می‌کند.'),
                true,
                Button::to(Words::yes('فقط از فروشگاه حذف شود'), self::ID, 'dex', $id),
                Button::to(Words::CANCEL, self::ID, 'show', $id),
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
     * The service's time (HTML lines): when it ends, as the bot words it — its date and what is left, a bar of the term
     * used while it runs —, its term while it waits for the first connection, never — or, deleted, the date on record —;
     * then the period it was renewed into and the shop's own removal of it, when they are coming.
     *
     * @param array<string, mixed> $service
     * @return list<string>
     */
    private static function time(array $service): array
    {
        $expires = Json::text($service, 'expires_at');
        $hours = (int) ($service['duration_hours'] ?? 0);
        $next = Json::part($service, 'next_period');
        $removes = Json::text($service, 'removes_at');
        $removal = $removes === null ? null : Fmt::moment($removes);
        $lines = [];
        if (($service['status'] ?? '') === 'deleted') {
            $lines[] = 'پایان: ' . ($expires === null ? Fmt::NONE : Fmt::date($expires));
        } elseif ($expires === null) {
            $lines[] = $hours > 0 ? 'در انتظار اولین اتصال · ' . Fmt::hours($hours) . ' از اولین اتصال' : 'بدون تاریخ پایان (نامحدود)';
        } else {
            $end = Fmt::moment($expires);
            $start = Fmt::moment(Json::text($service, 'starts_at'));
            $lines[] = 'پایان: <b>' . Fmt::e(Messages::expiry($end, $hours)) . '</b>' . (Json::on($service, 'expiring_soon') ? ' ⏳' : '');
            if ($end !== null && $start !== null && $end > $start) {
                $lines[] = Sales::bar(now()->getTimestamp() - $start->getTimestamp(), $end->getTimestamp() - $start->getTimestamp()) . ' · مدت ' . Fmt::hours($hours);
            } elseif ($hours > 0) {
                $lines[] = 'مدت: ' . Fmt::hours($hours);
            }
        }
        if ($next !== []) {
            $lines[] = 'دوره بعدی: از ' . Fmt::date(Json::text($next, 'starts_at')) . '، حداکثر ' . Fmt::bytes((int) ($next['traffic'] ?? 0)) . ' <i>(تمدید شده؛ حجم مصرف‌نشده دوره فعلی تا آن روز قابل استفاده است)</i>';
        }
        if ($removes !== null) {
            $lines[] = 'حذف خودکار: ' . Fmt::date($removes, true) . ' (' . ($removal !== null && $removal->isFuture() ? Sales::left($removes) : 'در نوبت حذف') . ')';
        }

        return $lines;
    }

    /**
     * Traffic used against the quota (HTML lines): the amounts and the share, and a bar — or unlimited.
     *
     * @param array<string, mixed> $traffic
     * @return list<string>
     */
    private static function usage(array $traffic): array
    {
        $used = (int) ($traffic['used'] ?? 0);
        $limit = (int) ($traffic['limit'] ?? 0);
        if ($limit <= 0) {
            return ['<b>' . Fmt::bytes($used) . '</b> مصرف · حجم نامحدود'];
        }

        return [
            '<b>' . Fmt::bytes($used) . '</b> از ' . Fmt::bytes($limit) . ' · ' . Fmt::bytes(max(0, $limit - $used)) . ' مانده',
            Sales::bar($used, $limit) . ' ' . Messages::percent($used, $limit),
        ];
    }

    /** @param array<string, mixed> $service */
    private function view(Admin $admin, array $service): View
    {
        $id = (int) ($service['id'] ?? 0);
        $status = (string) ($service['status'] ?? '');
        $plan = Json::text(Json::part($service, 'plan'), 'name');
        $claimed = Json::text($service, 'claimed_at') !== null;
        $devices = Json::part($service, 'next_devices');
        $hours = (int) ($service['duration_hours'] ?? 0);
        $test = Json::on($service, 'is_test');
        $link = (string) ($service['link'] ?? '');
        $dead = $status === 'deleted';
        $server = Json::part($service, 'server');

        $view = View::make(Icons::SERVICES . ' ' . (string) ($service['name'] ?? ''), ...Sales::under(Sales::SERVICES))->card([
            'وضعیت' => Fmt::e(Sales::word(Sales::SUBSCRIPTION_STATUS, $status)) . ($status === 'disabled' && Json::text($service, 'disabled_at') !== null ? ' · از ' . Fmt::date(Json::text($service, 'disabled_at'), true) : ''),
            ...Sales::customerFacts(Json::part($service, 'user')),
            'پلن' => $test ? 'اکانت تست رایگان' : ($plan !== null ? Fmt::e($plan) : ($claimed ? 'بدون پلن' : 'سرویس دلخواه')),
            'سرور' => Fmt::e((string) ($server['name'] ?? '')),
            'کاربر هم‌زمان' => Messages::devices((int) ($service['ip_limit'] ?? 0)) . ($devices === [] ? '' : ' · از ' . Fmt::date(Json::text($devices, 'from')) . ': ' . Messages::devices((int) ($devices['devices'] ?? 0))),
            'تمدید خودکار' => $hours > 0 && !$test ? Fmt::onOff(Json::on($service, 'auto_renew')) : null,
            ($claimed ? 'افزودن با لینک' : 'خرید') => Sales::when($service, $claimed ? 'claimed_at' : 'created_at'),
            'اولین اتصال' => Sales::when($service, 'starts_at'),
            'آخرین پرسش از پنل' => Sales::when($service, 'last_synced_at') ?? 'هنوز نه',
            'شماره' => Fmt::id($id),
        ]);
        $view->section('📶 حجم')->lines(...self::usage(Json::part($service, 'traffic')))
            ->section('⏳ زمان')->lines(...self::time($service))
            ->section(Sales::LINK . ' لینک اشتراک')
            ->line($dead ? Fmt::ltr($link) . "\n<i>این لینک دیگر کار نمی‌کند.</i>" : Fmt::code($link));
        if ($claimed && $plan === null && !$test) {
            $view->note('بدون پلن: مشتری هنگام تمدید یکی از پلن‌هایی را که روی سرور این سرویس فروخته می‌شوند انتخاب می‌کند.');
        }

        $actions = Json::part($service, 'actions');
        $seat = $admin->seat;
        $view->row(
            Json::on($actions, 'enable') ? Button::to(Words::ACTIVATE, self::ID, 'en', $id)->success() : null,
            Json::on($actions, 'extend') && $seat->holds(StaffGrant::Extend) ? Button::to(ExtendScreen::TITLE, ExtendScreen::ID, 'new', $id)->primary() : null,
        );
        $view->grid(
            2,
            Json::on($actions, 'sync') ? Button::to(Sales::SYNC . ' به‌روزرسانی از پنل', self::ID, 'sy', $id) : null,
            Json::on($actions, 'move') ? Button::to(self::MOVE, self::ID, 'mv', $id) : null,
            Json::on($actions, 'transfer') && $seat->holds(StaffGrant::Delete) ? Button::to(Sales::HAND_OVER . ' انتقال به مشتری دیگر', self::ID, 'tr', $id) : null,
            // A link of nothing, or too long to copy whole, is left out (Button::fits()).
            $dead ? null : Button::copy(Words::copy('لینک'), $link),
        );
        $view->row(...Sales::customerButtons(Json::part($service, 'user')));
        if ($admin->opens(self::SERVER)) {
            $view->row(Button::to(Icons::SERVERS . ' سرور ' . (string) ($server['name'] ?? ''), self::SERVER, 'show', (int) ($server['id'] ?? 0)));
        }
        // What switches it off or ends it, last.
        $view->row(
            Json::on($actions, 'disable') ? Button::to(Words::DEACTIVATE, self::ID, 'di', $id)->danger() : null,
            Json::on($actions, 'delete') && $seat->holds(StaffGrant::Delete) ? Button::to(Words::DELETE . ' سرویس', self::ID, 'de', $id)->danger() : null,
        );

        return $view->back(CustomerRowsScreen::back($admin, self::ID, $id, SubscriptionsScreen::ID))->refresh(self::ID, 'show', $id);
    }
}
