<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚡️ تایید خودکار واریز» (the payment methods page's card): card payments approved by their bank's SMS — its switch
 * (PUT /deposits/settings), then the two ways the SMS reach the shop. Once it is on, how to connect ForApp: its steps,
 * each phone app's webhook address and the key it sends (a spoiler to tap open, copied whole), a new key asked first
 * (POST /deposits/settings/key), whether its messages have been coming, and the SMS that approved nothing this week (the
 * deposits list). And Rikhtam, on or off: not connected, its steps and «🔌 اتصال ریختم» — its gateway's key asked
 * (the typed message taken away once read) and sent (PUT /deposits/settings/rikhtam, which switches the approval on);
 * connected, its gateway, since when and the shop's address there, «🔁 ثبت دوباره آدرس در ریختم» (POST …/rikhtam/hook)
 * — a refusal there pointing to a new key —, «🔑 تعویض کلید ریختم» (its key asked again, as connecting asks it) and
 * «✂️ قطع اتصال ریختم», asked first (DELETE …/rikhtam).
 *
 * Actions: `on` (0/1), `key` (asks), `rotate` (a new key), `rk` (asks Rikhtam's key; its answer `rk`), `rh` (the
 * address registered again), `rx` (asks) → `rxy` (Rikhtam's key forgotten).
 */
final class DepositSettingsScreen extends BaseScreen
{
    public const ID = 'pmd';

    /** Where ForApp's releases are: the owner downloads the app there, on the phone that gets the bank's SMS. */
    private const FORAPP_RELEASES = 'https://github.com/erfjab/forapp/releases/latest';

    /** Rikhtam's site: its panel, where the owner makes the shop's gateway and copies its key. */
    private const RIKHTAM = 'https://rikhtam.com';

    /** The least a card payment may be for Rikhtam to confirm its deposit, in Toman. */
    private const RIKHTAM_MINIMUM = 10_000;

    /** The bank SMS list (the sales section's). */
    private const DEPOSITS = 'dp';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin, $admin->api->get('/deposits/settings'));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'on':
                $settings = $admin->api->put('/deposits/settings', ['enabled' => self::id($args) === 1]);
                $admin->notice('✅ تنظیمات تایید خودکار واریز ذخیره شد');

                return $this->view($admin, $settings);
            case 'key':
                return $admin->confirm(
                    'کلید تازه ساخته شود؟',
                    Button::to('🔑 بله، ساخته شود', self::ID, 'rotate'),
                    Button::to('✖️ انصراف', self::ID),
                    'کلید فعلی همان لحظه از کار می‌افتد و تا کلید تازه را در فوراپ نگذارید، هیچ پیامکی به فروشگاه نمی‌رسد.',
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
                        'اگر کلید درگاه در پنل ریختم عوض یا حذف شده است، کلید تازه را با «🔑 تعویض کلید ریختم» بفرستید.',
                    ]);
                }
                $admin->notice('🔁 آدرس فروشگاه دوباره در ریختم ثبت شد');

                return $this->view($admin, $settings);
            case 'rx':
                return $admin->confirm(
                    'اتصال ریختم قطع شود؟',
                    Button::to('✂️ بله، قطع شود', self::ID, 'rxy'),
                    Button::to('✖️ انصراف', self::ID),
                    'کلید امنیتی از فروشگاه پاک می‌شود و واریزهایی که ریختم از این به بعد خبر می‌دهد هیچ پرداختی را تایید نمی‌کنند. درگاه در پنل ریختم سر جایش می‌ماند؛ برای وصل کردن دوباره، کلیدش را دوباره بفرستید.',
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
     * @param array<string, mixed> $settings
     */
    private function view(Admin $admin, array $settings): View
    {
        $on = Json::on($settings, 'enabled');
        $view = View::make('⚡️ تایید خودکار واریز', 'مدیریت', 'فروشگاه', 'روش‌های پرداخت')
            ->line('پرداخت‌های کارت به کارت با پیامک واریز بانک خودشان تایید می‌شوند و مشتری فقط مبلغ را دقیق کارت به کارت می‌کند. پیامک‌ها از یکی از دو راه به فروشگاه می‌رسند: برنامه رایگان فوراپ روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد، یا ریختم.')
            ->line()
            ->line('• تایید خودکار واریزها: ' . Fmt::onOff($on))
            ->line('<i>روشن که باشد، هر پرداخت کارت به کارت مبلغ خودش را دارد: چند تومان (حداکثر ۹۹۹ تومان) بیشتر از قیمت، تا واریزی که درست همان مبلغ را دارد همان پرداخت را تایید کند. خاموش که باشد، پرداخت‌ها مثل قبل با رسید بررسی می‌شوند.</i>');
        $view->row(Button::to($on ? '⏸ خاموش کردن' : '▶️ روشن کردن', self::ID, 'on', $on ? 0 : 1));

        if ($on && is_string($settings['key'] ?? null)) {
            $sources = Json::rows($settings, 'sources');
            $view->line()
                ->line('<b>🔌 اتصال فوراپ</b>')
                ->line('فوراپ هر پیامک بانک را با کلید ' . Fmt::ltr('API') . ' به آدرس وب‌هوک می‌فرستد؛ تا آدرس و کلید زیر را در فوراپ نگذارید، پیامکی از آن به فروشگاه نمی‌رسد.')
                ->line('۱. فوراپ را روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد نصب کنید (دکمه «دانلود فوراپ»).')
                ->line('۲. در برنامه یک مقصد وب‌هوک تازه اضافه کنید.')
                ->line('۳. آدرس وب‌هوک و کلید ' . Fmt::ltr('API') . ' زیر را در آن بگذارید.')
                ->line('۴. پیام آزمایشی برنامه را بفرستید؛ رسیدنش همین‌جا دیده می‌شود.')
                ->line();
            foreach ($sources as $source) {
                $view->line((count($sources) > 1 ? 'آدرس وب‌هوک ' . Fmt::e((string) ($source['label'] ?? '')) : 'آدرس وب‌هوک') . ':')
                    ->line(Fmt::code((string) ($source['webhook_url'] ?? '')));
            }
            $view->line('کلید ' . Fmt::ltr('API') . ' (برای دیدن بزنید؛ با زدن روی آن کپی می‌شود):')
                ->line('<tg-spoiler>' . Fmt::code($settings['key']) . '</tg-spoiler>')
                ->line();
            $test = is_string($settings['last_test_at'] ?? null) ? $settings['last_test_at'] : null;
            $sms = is_string($settings['last_received_at'] ?? null) ? $settings['last_received_at'] : null;
            $view->line($test === null && $sms === null
                ? '🟡 هنوز پیامی نرسیده است.'
                : '🟢 آخرین پیام آزمایشی: ' . ($test === null ? 'هنوز نرسیده' : Fmt::date($test, true)) . ' · آخرین پیامک بانک: ' . ($sms === null ? 'هنوز نرسیده' : Fmt::date($sms, true)));
            $waiting = (int) ($settings['waiting'] ?? 0);
            if ($waiting > 0) {
                $view->line('⚠️ ' . Fmt::number($waiting) . ' پیامک واریز در هفت روز گذشته هیچ پرداختی را تایید نکرده است.');
                if ($admin->opens(self::DEPOSITS)) {
                    $view->row(Button::to('📩 دیدن واریزها', self::DEPOSITS));
                }
            }
            $view->row(Button::url('📲 دانلود فوراپ', self::FORAPP_RELEASES), Button::to('🔑 ساخت کلید تازه', self::ID, 'key'));
        }
        $this->rikhtamPart($view, $settings, $on);

        return $view->row(Button::to('🔄 تازه‌سازی', self::ID))->back(PaymentMethodsScreen::ID);
    }

    /**
     * Rikhtam's part, on or off: connected, its gateway, since when and the shop's address there (copied at a tap); not
     * connected, its steps.
     *
     * @param array<string, mixed> $settings
     */
    private function rikhtamPart(View $view, array $settings, bool $on): void
    {
        $rikhtam = self::rikhtam($settings);
        $address = is_string($rikhtam['callback_url'] ?? null) ? $rikhtam['callback_url'] : null;
        $domain = is_string($rikhtam['domain'] ?? null) ? $rikhtam['domain'] : self::host($address);
        $view->line()
            ->line('<b>🔗 اتصال ریختم</b>')
            ->line('ریختم پیامک‌های واریز حساب بانکی را در برنامه خودش می‌خواند و هر واریز را به فروشگاه خبر می‌دهد؛ برای هر واریزی که تایید شود کارمزد خودش را می‌گیرد. فوراپ رایگان است.');

        if (($rikhtam['connected'] ?? false) !== true) {
            $view->line('• ریختم: ⚪️ وصل نیست')
                ->line('۱. در پنل ریختم، بخش «درگاه‌ها»، یک درگاه با دامنه این فروشگاه' . ($domain === null ? '' : ' (' . Fmt::ltr($domain) . ')') . ' بسازید و «کلید امنیتی» آن را کپی کنید.')
                ->line('۲. «🔌 اتصال ریختم» را بزنید و کلید را بفرستید؛ فروشگاه آدرس خودش را در ریختم ثبت می‌کند و لازم نیست چیزی را آن‌جا بگذارید.')
                ->line('۳. در برنامه ریختم، حساب بانکی کارت‌هایی را که مشتری‌ها به آن‌ها واریز می‌کنند به همین درگاه وصل کنید.')
                ->line('۴. با «تست سلامت درگاه» در پنل یا برنامه ریختم، اتصال را بسنجید.');
            // Rikhtam tells only an https address: a shop on another one is told so, not asked a key Rikhtam would refuse.
            if ($address === null || !str_starts_with($address, 'https://')) {
                $view->line('⚠️ آدرس سایت فروشگاه با ' . Fmt::ltr('https') . ' شروع نمی‌شود و ریختم فقط به آدرس ' . Fmt::ltr('https') . ' خبر می‌دهد؛ تا آدرس سایت ' . Fmt::ltr('https') . ' نشود، ریختم وصل نمی‌شود.')
                    ->row(Button::url('🌐 پنل ریختم', self::RIKHTAM));

                return;
            }
            if (!$on) {
                $view->line('<i>تایید خودکار واریز الان خاموش است؛ با اتصال ریختم روشن می‌شود.</i>');
            }
            $view->row(Button::to('🔌 اتصال ریختم', self::ID, 'rk'), Button::url('🌐 پنل ریختم', self::RIKHTAM));

            return;
        }

        $project = is_numeric($rikhtam['project_id'] ?? null) ? (int) $rikhtam['project_id'] : null;
        $view->line('• ریختم: 🟢 وصل است')
            ->line('• درگاه: ' . Fmt::ltr($domain) . ($project === null ? '' : ' (شماره ' . Fmt::digits($project) . ' در ریختم)'))
            ->line('• وصل شده در: ' . Fmt::date(is_string($rikhtam['connected_at'] ?? null) ? $rikhtam['connected_at'] : null, true))
            ->line('آدرس فروشگاه در ریختم (فروشگاه خودش ثبتش کرده؛ برای مراجعه است و لازم نیست جایی بگذارید):')
            ->line(Fmt::code($address));
        if (!$on) {
            $view->line('⚠️ تایید خودکار واریز خاموش است؛ تا روشنش نکنید، واریزهای ریختم خودکار هیچ پرداختی را تایید نمی‌کنند. پیوست دستی یک واریز به پرداخت کار می‌کند.');
        }
        $view->line('<i>مشتری‌ای که مبلغ را گرد کند خودکار تایید نمی‌شود: واریزش را در برنامه ریختم با شماره پرداخت، یا در صفحه همان پرداخت با «📎 پیوست واریز ریختم» به آن وصل کنید. ریختم پرداخت‌های کمتر از ' . Fmt::money(self::RIKHTAM_MINIMUM) . ' را تایید نمی‌کند. سلامت اتصال را با «تست سلامت درگاه» در پنل یا برنامه ریختم بسنجید.</i>')
            // Named for Rikhtam: ForApp's address and key are on the same screen, its buttons a row above.
            ->row(Button::to('🔁 ثبت دوباره آدرس در ریختم', self::ID, 'rh'), Button::to('🔑 تعویض کلید ریختم', self::ID, 'rk'))
            ->row(Button::to('✂️ قطع اتصال ریختم', self::ID, 'rx'));
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

    /** The shop's domain as its address on Rikhtam names it — the one Rikhtam's gateway is made with; null for none. */
    private static function host(mixed $address): ?string
    {
        $host = is_string($address) ? parse_url($address, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }
}
