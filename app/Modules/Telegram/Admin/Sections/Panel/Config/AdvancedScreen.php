<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «پیشرفته» of config.php: the log, the panels' session, the timeout of outgoing requests, and the cron run from the
 * web — its token, and the whole address and the cPanel line, shown on a tap (the screen shows the address masked, as the
 * panel does until its copy button). The session's two are the owner's themselves alone: an admin sees them, 🔒.
 *
 * Actions besides the form's: `cron` (the whole address and line shown, until the next tap).
 */
final class AdvancedScreen extends ConfigScreen
{
    public const ID = 'ppv';

    public const TITLE = Icons::ADVANCED . ' پیشرفته';

    /** The whole cron address, for the update that asked for it (shownFor) alone. */
    private ?string $shown = null;

    private ?Admin $shownFor = null;

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function group(): string
    {
        return 'advanced';
    }

    protected function done(): string
    {
        return '✅ تنظیمات پیشرفته ذخیره شد.';
    }

    protected function ownersOnly(): array
    {
        return ['session_lifetime' => ActorRefusedException::OWNERS_SESSION, 'session_secure_cookie' => ActorRefusedException::OWNERS_SESSION];
    }

    protected function formOf(array $config): DraftForm
    {
        $levels = [];
        foreach (is_array(self::meta($config)['log_levels'] ?? null) ? self::meta($config)['log_levels'] : [] as $level) {
            if (is_string($level)) {
                $levels[$level] = $level;
            }
        }

        return DraftForm::fixed([
            DraftForm::field('log_level', 'choice', 'سطح لاگ', required: true, options: $levels),
            DraftForm::field('session_lifetime', 'number', "طول \u{2068}Session\u{2069} پنل‌ها", 'بعد از این مدت بی‌کاری باید دوباره وارد شد؛ در پنل شما و پنل نماینده‌ها. فقط خود مالک آن را عوض می‌کند.', required: true, unit: 'دقیقه', min: 5, max: 43200),
            DraftForm::field('http_timeout', 'number', 'تایم‌اوت درخواست‌های خروجی', required: true, unit: 'ثانیه', min: 5, max: 120),
            DraftForm::field('session_secure_cookie', 'toggle', "کوکی \u{2068}Session\u{2069} همیشه امن", 'روی https کوکی خودکار امن می‌شود. فقط وقتی روشن کنید که سایت پشت پروکسی (مثلا Cloudflare) است و PHP درخواست را http می‌بیند؛ روی http واقعی ورود به پنل غیرممکن می‌شود. فقط خود مالک آن را عوض می‌کند.', default: false),
            DraftForm::field('cron_token', 'secret', "توکن \u{2068}Cron\u{2069}", 'کارهای زمان‌بندی‌شده (تمدید خودکار، یادآوری‌ها، همگام‌سازی سرویس‌ها) با صدا زدن آدرس Cron اجرا می‌شوند: توکن بسازید و ذخیره کنید، بعد خط Cron را که این‌جا آماده می‌شود در فرم Cron Jobs در cPanel بگذارید، یا آدرس را به یک سرویس Cron بیرونی بدهید که هر دقیقه صدایش بزند.', generate: 20),
        ], self::groupOf($config, 'advanced'));
    }

    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void
    {
        $masked = self::meta($this->config($admin))['cron_url'] ?? null;
        if (!is_string($masked)) {
            return;
        }
        $whole = $this->shownFor === $admin ? $this->shown : null;
        $view->section("⏱ آدرس \u{2068}Cron\u{2069}");
        if ($whole === null) {
            $view->card(['آدرس' => Fmt::ltr($masked)])->row(Button::to("👁 آدرس و خط \u{2068}Cron\u{2069}", self::ID, 'cron'));

            return;
        }
        $line = 'curl -fsS ' . $whole . ' > /dev/null';
        // Shown on this screen alone, and copied from it: the token is in it, and the next tap hides it again.
        $view->card(['آدرس' => Fmt::code($whole), "خط \u{2068}Cron\u{2069}" => Fmt::code($line)])
            ->note("این خط برای فرم «\u{2068}Cron Jobs\u{2069}» در \u{2068}cPanel\u{2069} است: زمان‌بندی را هر دقیقه بگذارید (\u{2068}Once Per Minute\u{2069}) و خط را در فیلد \u{2068}Command\u{2069} بچسبانید. آدرس، توکن را دارد؛ آن را جای عمومی نگذارید. با دکمه بعدی پنهان می‌شود.")
            ->row(Button::copy(Words::copy('آدرس'), $whole), Button::copy(Words::copy('خط Cron'), $line));
    }

    protected function action(Admin $admin, string $action, array $args, DraftForm $form, string $driver, array $values): View
    {
        if ($action === 'cron') {
            $url = $admin->api->get('/settings/config/cron-url')['url'] ?? null;
            // Shown on this screen alone: kept nowhere, gone with the next tap.
            [$this->shown, $this->shownFor] = [is_string($url) ? $url : null, $admin];
        }

        return $this->card($admin, $form);
    }
}
