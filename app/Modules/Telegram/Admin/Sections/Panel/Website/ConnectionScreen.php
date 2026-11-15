<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «اتصال» of the website: its API on or off and the addresses its pages call it from, then the API's own address for the
 * site's developer — copied at a tap — and its store key, renewed on a yes (the old address stops at once).
 *
 * Actions besides the card's: `key` (asks), `rekey` (a new key).
 */
final class ConnectionScreen extends WebsiteCard
{
    public const ID = 'wsc';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🔌 اتصال وب‌سایت';
    }

    protected function intro(): string
    {
        return "روشن و خاموش کردن \u{2068}API\u{2069} وب‌سایت، و آدرس‌هایی که اجازه دارند از مرورگر بازدیدکننده به آن درخواست بفرستند.";
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('enabled', "\u{2068}API\u{2069} وب‌سایت روشن باشد", 'خاموش که باشد، هر درخواستی به آدرس API جواب «این فروشگاه فعلا در دسترس نیست» می‌گیرد.'),
            FieldDef::url('url', 'آدرس وب‌سایت', "\u{2068}Origin\u{2069} این آدرس می‌تواند از مرورگر بازدیدکننده به \u{2068}API\u{2069} درخواست بفرستد. برای روشن کردن \u{2068}API\u{2069} لازم است. مثل \u{2068}https://example.com\u{2069}")->nullable(),
            FieldDef::textarea('origins', "\u{2068}Origin\u{2069}های مجاز دیگر", "هر \u{2068}Origin\u{2069} در یک خط؛ مثلا \u{2068}http://localhost:3000\u{2069} وقتی سایت در حال ساخت است. حداکثر ۱۰ \u{2068}Origin\u{2069}.")->nullable(),
        ];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $website = Json::part($values, 'website');
        $view->line()
            ->line("<b>🔗 آدرس \u{2068}API\u{2069}</b>")
            ->line('این آدرس را به برنامه‌نویس وب‌سایت بدهید؛ همه مسیرهای API زیر آن است:')
            ->line(Fmt::code(is_string($website['base_url'] ?? null) ? $website['base_url'] : null))
            ->line('کلید فروشگاه: ' . Fmt::code(is_string($website['key'] ?? null) ? $website['key'] : null))
            ->line('<i>کلید فروشگاه را در آدرس مشخص می‌کند و رمز نیست.</i>')
            ->line("<i>وب‌سایت درخواست‌هایش را به همین آدرس می‌فرستد؛ برای مشتری واردشده، توکنی را که ورودش برگردانده در هدر \u{2068}<code>Authorization: Bearer …</code>\u{2069} می‌گذارد.</i>")
            ->row(Button::to('🔑 ساخت کلید جدید', self::ID, 'key'));
    }

    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        switch ($action) {
            case 'key':
                return $admin->confirm(
                    'کلید جدید ساخته شود؟',
                    Button::to('🔑 بله، کلید جدید', self::ID, 'rekey'),
                    Button::to('✖️ انصراف', self::ID),
                    'آدرس API فعلی بلافاصله از کار می‌افتد و وب‌سایت باید آدرس جدید را بگیرد.',
                );
            case 'rekey':
                $values = WebsiteSource::flat($admin->api->post('/website/key'));
                $admin->notice('✅ کلید جدید ساخته شد؛ آدرس تازه را به وب‌سایت بدهید.');

                return $this->card($admin, $values);
        }

        return parent::action($admin, $action, $args, $values);
    }
}
