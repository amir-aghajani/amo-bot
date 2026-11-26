<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Sales\DepositsScreen;
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

/**
 * «⚡️ تایید خودکار واریز» (the payment methods page's card): card payments approved by their bank's SMS. How it stands
 * first, in one card — the switch (PUT /deposits/settings), whether ForApp's messages have been coming, whether Rikhtam
 * is connected, the SMS that approved nothing this week —, then each of the two ways the SMS reach the shop as a
 * section, its steps folded away. ForApp, once the approval is on: each phone app's webhook address and the key it sends
 * (a spoiler to tap open; both copied by their buttons too), a new key asked first (POST /deposits/settings/key), and the
 * deposits list when SMS approved nothing. Rikhtam, on or off: not connected, «🔌 اتصال ریختم» — its gateway's key
 * asked (the typed message taken away once read) and sent (PUT /deposits/settings/rikhtam, which switches the approval
 * on), a shop not on https told so up front; connected, its gateway, since when and the shop's address there, «🔁 ثبت
 * دوباره آدرس در ریختم» (POST …/rikhtam/hook) — a refusal there pointing to a new key —, «🔑 تعویض کلید ریختم» (its key
 * asked again, as connecting asks it) and «✂️ قطع اتصال ریختم», asked first (DELETE …/rikhtam). «🔄 تازه‌سازی» reads it
 * again: whether the test message came.
 *
 * Actions: `on` (0/1), `key` (asks), `rotate` (a new key), `rk` (asks Rikhtam's key; its answer `rk`), `rh` (the
 * address registered again), `rx` (asks) → `rxy` (Rikhtam's key forgotten).
 */
final class DepositSettingsScreen extends BaseScreen
{
    public const ID = 'pmd';

    public const TITLE = '⚡️ تایید خودکار واریز';

    /** A new key for Rikhtam: the button, and the refusal of a registration that points to it. */
    private const NEW_RIKHTAM_KEY = '🔑 تعویض کلید ریختم';

    /** Where ForApp's releases are: the owner downloads the app there, on the phone that gets the bank's SMS. */
    private const FORAPP_RELEASES = 'https://github.com/erfjab/forapp/releases/latest';

    /** Rikhtam's site: its panel, where the owner makes the shop's gateway and copies its key. */
    private const RIKHTAM = 'https://rikhtam.com';

    /** The least a card payment may be for Rikhtam to confirm its deposit, in Toman. */
    private const RIKHTAM_MINIMUM = 10_000;

    /** Why Rikhtam is not connected while the shop's address is not on https (the panel's words). */
    private const NOT_HTTPS = 'آدرس سایت فروشگاه با https شروع نمی‌شود و ریختم فقط به آدرس https خبر می‌دهد؛ تا آدرس سایت https نشود، ریختم وصل نمی‌شود.';

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin, $admin->api->get('/deposits/settings'));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'on':
                $on = self::id($args) === 1;
                $settings = $admin->api->put('/deposits/settings', ['enabled' => $on]);
                $admin->notice($on ? '▶️ تایید خودکار واریز روشن شد' : '⏸ تایید خودکار واریز خاموش شد');

                return $this->view($admin, $settings);
            case 'key':
                return $admin->confirm(
                    'کلید تازه ساخته شود؟',
                    Button::to(Words::yes('ساخته شود', '🔑'), self::ID, 'rotate'),
                    Button::to(Words::CANCEL, self::ID),
                    'کلید فعلی همان لحظه از کار می‌افتد و تا کلید تازه را در فوراپ نگذارید، هیچ پیامکی به فروشگاه نمی‌رسد.',
                    danger: true,
                );
            case 'rotate':
                $settings = $admin->api->post('/deposits/settings/key');
                $admin->notice('🔑 کلید تازه ساخته شد؛ آن را در فوراپ بگذارید');

                return $this->view($admin, $settings);
            case 'rk':
                $settings = $admin->api->get('/deposits/settings');
                $on = Json::on($settings, 'enabled');
                $rikhtam = self::rikhtam($settings);
                $connected = Json::on($rikhtam, 'connected');
                $domain = self::host($rikhtam['callback_url'] ?? null);
                if (!$connected && !self::https($rikhtam)) {
                    // A window from before the address changed: not asked a key Rikhtam would refuse (rikhtamPart()'s blocker).
                    $admin->alert(self::NOT_HTTPS);

                    return $this->view($admin, $settings);
                }

                return $admin->ask(new Prompt(
                    $connected ? 'کلید امنیتی تازه درگاه ریختم' : 'کلید امنیتی درگاه ریختم',
                    self::ID,
                    'rk',
                    [$on ? 1 : 0, $connected ? 1 : 0],
                    implode("\n", array_filter([
                        $connected
                            ? 'کلید امنیتی تازه درگاه را از پنل ریختم، بخش «درگاه‌ها»، همین‌جا بفرستید؛ جای کلید فعلی را می‌گیرد و آدرس فروشگاه دوباره در ریختم ثبت می‌شود.'
                            : 'در پنل ریختم، بخش «درگاه‌ها»، درگاهی با دامنه این فروشگاه' . ($domain === null ? '' : ' (' . Fmt::ltr($domain) . ')') . ' بسازید و «کلید امنیتی» آن را همین‌جا بفرستید؛ فروشگاه آدرس خودش را در ریختم ثبت می‌کند.',
                        $on ? null : 'تایید خودکار واریز الان خاموش است و با اتصال ریختم روشن می‌شود.',
                        'پیامتان بعد از خواندن پاک می‌شود و دیگر نشان داده نمی‌شود.',
                    ])),
                    Button::to(Words::CANCEL, self::ID),
                ));
            case 'rh':
                try {
                    $settings = $admin->api->post('/deposits/settings/rikhtam/hook');
                } catch (ApiRefusal $refusal) {
                    if ($refusal->status !== 422) {
                        throw $refusal;
                    }

                    // Refused by Rikhtam — most often a key changed or made anew there: the way to the new one with it.
                    return $this->view($admin, $admin->api->get('/deposits/settings'))->notices([
                        '⚠️ ' . $refusal->lines(),
                        'اگر کلید درگاه در پنل ریختم عوض یا حذف شده است، کلید تازه را با «' . self::NEW_RIKHTAM_KEY . '» بفرستید.',
                    ]);
                }
                $admin->notice('🔁 آدرس فروشگاه دوباره در ریختم ثبت شد');

                return $this->view($admin, $settings);
            case 'rx':
                return $admin->confirm(
                    'اتصال ریختم قطع شود؟',
                    Button::to(Words::yes('قطع شود', '✂️'), self::ID, 'rxy'),
                    Button::to(Words::CANCEL, self::ID),
                    'کلید امنیتی از فروشگاه پاک می‌شود و واریزهایی که ریختم از این به بعد خبر می‌دهد هیچ پرداختی را تایید نمی‌کنند. درگاه در پنل ریختم سر جایش می‌ماند؛ برای وصل کردن دوباره، کلیدش را دوباره بفرستید.',
                    danger: true,
                );
            case 'rxy':
                $settings = $admin->api->delete('/deposits/settings/rikhtam');
                $admin->notice('✂️ اتصال ریختم قطع شد');

                return $this->view($admin, $settings);
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action !== 'rk') {
            return $this->show($admin, $args);
        }
        // A key Rikhtam refuses (wrong, or made for another domain) is said over the question, asked again.
        $settings = $admin->api->put('/deposits/settings/rikhtam', ['secret' => $answer->latin()]);
        $admin->notice(match (true) {
            self::id($args, 1) === 1 => '🔑 کلید ریختم عوض شد و آدرس فروشگاه دوباره در ریختم ثبت شد',
            self::id($args) === 1 => '✅ ریختم وصل شد',
            default => '✅ ریختم وصل شد و تایید خودکار واریز روشن شد',
        });

        return $this->view($admin, $settings);
    }

    /**
     * How it stands in a card, the switch's button, ForApp's section (while it is on) and Rikhtam's, what it is folded
     * away under them.
     *
     * @param array<string, mixed> $settings
     */
    private function view(Admin $admin, array $settings): View
    {
        $on = Json::on($settings, 'enabled');
        $rikhtam = self::rikhtam($settings);
        $connected = Json::on($rikhtam, 'connected');
        $waiting = (int) ($settings['waiting'] ?? 0);
        $view = View::make(self::TITLE, ...PaymentWords::under())
            ->card([
                'تایید خودکار واریزها' => Fmt::onOff($on),
                'فوراپ' => $on ? self::heard($settings) : Words::NONE,
                'ریختم' => $connected ? '🟢 وصل است' : '⏸ وصل نیست',
                'پیامک‌های بی‌پرداخت در ۷ روز' => $waiting > 0 ? '⚠️ ' . Fmt::number($waiting) : null,
            ])
            ->row($on ? Button::to(Words::TURN_OFF, self::ID, 'on', 0)->danger() : Button::to(Words::TURN_ON, self::ID, 'on', 1)->success());
        if ($waiting > 0 && $admin->opens(DepositsScreen::ID)) {
            $view->row(Button::to(Icons::DEPOSITS . ' دیدن واریزها', DepositsScreen::ID));
        }

        if ($on && is_string($settings['key'] ?? null)) {
            $this->forApp($view, $settings, $settings['key']);
        }
        $this->rikhtamPart($view, $rikhtam, $on);

        return $view->help('پرداخت‌های کارت به کارت با پیامک واریز بانک خودشان تایید می‌شوند و مشتری فقط مبلغ را دقیق کارت به کارت می‌کند. روشن که باشد، هر پرداخت کارت به کارت مبلغ خودش را دارد: چند تومان (حداکثر ۹۹۹ تومان) بیشتر از قیمت، تا واریزی که درست همان مبلغ را دارد همان پرداخت را تایید کند. خاموش که باشد، پرداخت‌ها مثل قبل با رسید بررسی می‌شوند. پیامک‌ها از یکی از دو راه به فروشگاه می‌رسند: برنامه رایگان فوراپ روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد، یا ریختم.')
            ->back(PaymentMethodsScreen::ID)
            ->refresh(self::ID);
    }

    /**
     * ForApp's section: each phone app's webhook address and the key (a spoiler, copied whole), each copied by its
     * button too; the app's download, a new key; its steps folded away.
     *
     * @param array<string, mixed> $settings
     */
    private function forApp(View $view, array $settings, string $key): void
    {
        $sources = Json::rows($settings, 'sources');
        $view->section('📲 اتصال فوراپ');
        $copies = [];
        foreach ($sources as $source) {
            $url = (string) ($source['webhook_url'] ?? '');
            $named = count($sources) > 1 ? ' ' . (string) ($source['label'] ?? '') : '';
            $view->line('آدرس وب‌هوک' . Fmt::e($named) . ': ' . Fmt::code($url));
            $copies[] = Button::copy(Words::copy('آدرس وب‌هوک' . $named), $url);
        }
        $view->line('کلید ' . Fmt::ltr('API') . ' (برای دیدن بزنید): <tg-spoiler>' . Fmt::code($key) . '</tg-spoiler>')
            ->help('<b>اتصال فوراپ</b>' . "\n" . 'فوراپ هر پیامک بانک را با کلید ' . Fmt::ltr('API') . ' به آدرس وب‌هوک می‌فرستد:' . "\n"
                . '۱. فوراپ را روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد نصب کنید («📲 دانلود فوراپ»).' . "\n"
                . '۲. در برنامه یک مقصد وب‌هوک تازه اضافه کنید.' . "\n"
                . '۳. آدرس وب‌هوک و کلید ' . Fmt::ltr('API') . ' بالا را در آن بگذارید (با دکمه‌های کپی).' . "\n"
                . '۴. پیام آزمایشی برنامه را بفرستید و «' . Words::REFRESH . '» را بزنید؛ رسیدنش در خلاصه بالا دیده می‌شود.');
        $view->tiles(...$copies, ...[Button::copy(Words::copy('کلید'), $key)])
            ->row(Button::url('📲 دانلود فوراپ', self::FORAPP_RELEASES), Button::to('🔑 ساخت کلید تازه', self::ID, 'key'));
    }

    /**
     * Rikhtam's section, on or off: connected, its gateway, since when and the shop's address there (copied at a tap);
     * not connected, why it cannot be (no https) or its button, its steps folded away.
     *
     * @param array<string, mixed> $rikhtam
     */
    private function rikhtamPart(View $view, array $rikhtam, bool $on): void
    {
        $address = is_string($rikhtam['callback_url'] ?? null) ? $rikhtam['callback_url'] : null;
        $domain = is_string($rikhtam['domain'] ?? null) ? $rikhtam['domain'] : self::host($address);
        $view->section('🔗 اتصال ریختم');

        if (($rikhtam['connected'] ?? false) !== true) {
            $view->line('ریختم پیامک‌های واریز حساب بانکی را در برنامه خودش می‌خواند و هر واریز را خبر می‌دهد؛ برای هر واریز تاییدشده کارمزد می‌گیرد (فوراپ رایگان است).');
            $steps = '<b>اتصال ریختم</b>' . "\n"
                . '۱. در پنل ریختم، بخش «درگاه‌ها»، یک درگاه با دامنه این فروشگاه' . ($domain === null ? '' : ' (' . Fmt::ltr($domain) . ')') . ' بسازید و «کلید امنیتی» آن را کپی کنید.' . "\n"
                . '۲. «🔌 اتصال ریختم» را بزنید و کلید را بفرستید؛ فروشگاه آدرس خودش را در ریختم ثبت می‌کند و لازم نیست چیزی را آن‌جا بگذارید.' . "\n"
                . '۳. در برنامه ریختم، حساب بانکی کارت‌هایی را که مشتری‌ها به آن‌ها واریز می‌کنند به همین درگاه وصل کنید.' . "\n"
                . '۴. با «تست سلامت درگاه» در پنل یا برنامه ریختم، اتصال را بسنجید.';
            // Rikhtam tells only an https address: a shop on another one is told so, not asked a key Rikhtam would refuse.
            if (!self::https($rikhtam)) {
                $view->line('⚠️ ' . str_replace('https', Fmt::ltr('https'), Fmt::e(self::NOT_HTTPS)))
                    ->help($steps)
                    ->row(Button::url('🌐 پنل ریختم', self::RIKHTAM));

                return;
            }
            if (!$on) {
                $view->note('تایید خودکار واریز الان خاموش است؛ با اتصال ریختم روشن می‌شود.');
            }
            $view->help($steps)
                ->row(Button::to('🔌 اتصال ریختم', self::ID, 'rk')->primary(), Button::url('🌐 پنل ریختم', self::RIKHTAM));

            return;
        }

        $project = is_numeric($rikhtam['project_id'] ?? null) ? (int) $rikhtam['project_id'] : null;
        $view->card([
            'درگاه' => Fmt::ltr($domain) . ($project === null ? '' : ' (شماره ' . Fmt::digits($project) . ' در ریختم)'),
            'وصل شده در' => Fmt::date(is_string($rikhtam['connected_at'] ?? null) ? $rikhtam['connected_at'] : null, true),
            'آدرس فروشگاه در ریختم' => Fmt::code($address),
        ]);
        if (!$on) {
            $view->line('⚠️ تایید خودکار واریز خاموش است؛ تا روشنش نکنید، واریزهای ریختم خودکار هیچ پرداختی را تایید نمی‌کنند. پیوست دستی یک واریز به پرداخت کار می‌کند.');
        }
        $view->help('<b>ریختم</b>' . "\n" . 'آدرس فروشگاه را خود فروشگاه در ریختم ثبت کرده؛ برای مراجعه است و لازم نیست جایی بگذارید. مشتری‌ای که مبلغ را گرد کند خودکار تایید نمی‌شود: واریزش را در برنامه ریختم با شماره پرداخت، یا در صفحه همان پرداخت با «📎 پیوست واریز ریختم» به آن وصل کنید. ریختم پرداخت‌های کمتر از ' . Fmt::money(self::RIKHTAM_MINIMUM) . ' را تایید نمی‌کند. سلامت اتصال را با «تست سلامت درگاه» در پنل یا برنامه ریختم بسنجید؛ ریختم برای هر واریزی که تایید شود کارمزد خودش را می‌گیرد.')
            // Named for Rikhtam: ForApp's address and key are on the same screen, its buttons above.
            ->row($address === null ? null : Button::copy(Words::copy('آدرس ریختم'), $address))
            ->row(Button::to('🔁 ثبت دوباره آدرس در ریختم', self::ID, 'rh'), Button::to(self::NEW_RIKHTAM_KEY, self::ID, 'rk'))
            ->row(Button::to('✂️ قطع اتصال ریختم', self::ID, 'rx')->danger());
    }

    /**
     * Whether ForApp's messages have been coming, for the card: the last test and the last bank SMS, or none yet.
     *
     * @param array<string, mixed> $settings
     */
    private static function heard(array $settings): string
    {
        $test = is_string($settings['last_test_at'] ?? null) ? $settings['last_test_at'] : null;
        $sms = is_string($settings['last_received_at'] ?? null) ? $settings['last_received_at'] : null;
        if ($test === null && $sms === null) {
            return Words::PENDING . ' · هنوز پیامی نرسیده';
        }

        return '🟢 آخرین پیامک بانک: ' . ($sms === null ? 'هنوز نرسیده' : Fmt::date($sms, true)) . ' · آزمایشی: ' . ($test === null ? 'هنوز نرسیده' : Fmt::date($test, true));
    }

    /**
     * Rikhtam's connection as the settings carry it.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function rikhtam(array $settings): array
    {
        return Json::part($settings, 'rikhtam');
    }

    /**
     * Whether the shop's address Rikhtam would be told is on https: Rikhtam tells no other.
     *
     * @param array<string, mixed> $rikhtam
     */
    private static function https(array $rikhtam): bool
    {
        return is_string($rikhtam['callback_url'] ?? null) && str_starts_with($rikhtam['callback_url'], 'https://');
    }

    /** The shop's domain as its address on Rikhtam names it — the one Rikhtam's gateway is made with; null for none. */
    private static function host(mixed $address): ?string
    {
        $host = is_string($address) ? parse_url($address, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }
}
