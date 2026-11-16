<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «اتصال» of the website: its API on or off and the addresses its pages call it from, then the API's own address for the
 * site's developer and its store key — each copied at a tap —, the key renewed on a yes (the old address stops at once,
 * so the yes is red).
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
        return "روشن و خاموش کردن \u{2068}API\u{2069} وب‌سایت، و آدرس‌هایی که اجازه دارند از مرورگر بازدیدکننده به آن درخواست بفرستند.\n\n"
            . 'آدرس API را به برنامه‌نویس وب‌سایت بدهید؛ همه مسیرهای API زیر آن است. کلید فروشگاه را در آدرس مشخص می‌کند و رمز نیست. '
            . "وب‌سایت درخواست‌هایش را به همین آدرس می‌فرستد؛ برای مشتری واردشده، توکنی را که ورودش برگردانده در هدر \u{2068}<code>Authorization: Bearer …</code>\u{2069} می‌گذارد.";
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('enabled', "\u{2068}API\u{2069} وب‌سایت روشن باشد", 'خاموش که باشد، هر درخواستی به آدرس API جواب «این فروشگاه فعلا در دسترس نیست» می‌گیرد.'),
            FieldDef::url('url', 'آدرس وب‌سایت', "\u{2068}Origin\u{2069} این آدرس می‌تواند از مرورگر بازدیدکننده به \u{2068}API\u{2069} درخواست بفرستد. برای روشن کردن \u{2068}API\u{2069} لازم است.")->nullable()->limits('حداکثر ۲۵۵ نویسه'),
            FieldDef::textarea('origins', "\u{2068}Origin\u{2069}های مجاز دیگر", "هر \u{2068}Origin\u{2069} در یک خط؛ مثلا وقتی سایت در حال ساخت است.")->nullable()->example('http://localhost:3000')->limits('حداکثر ۱۰ آدرس'),
        ];
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        if ($field->name !== 'origins') {
            return null;
        }
        $origins = WebsiteSource::origins(is_string($value) ? $value : '');

        return $origins === [] ? '' : implode('، ', array_map(Fmt::ltr(...), $origins));
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $website = Json::part($values, 'website');
        $address = is_string($website['base_url'] ?? null) ? $website['base_url'] : null;
        $key = is_string($website['key'] ?? null) ? $website['key'] : null;
        $view->section("🔗 آدرس \u{2068}API\u{2069}")
            ->card(['آدرس' => Fmt::code($address), 'کلید فروشگاه' => Fmt::code($key)])
            ->row($address === null ? null : Button::copy(Words::copy('آدرس'), $address), $key === null ? null : Button::copy(Words::copy('کلید'), $key))
            ->row(Button::to('🔑 ساخت کلید جدید', self::ID, 'key')->danger());
    }

    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        switch ($action) {
            case 'key':
                return $admin->confirm(
                    'کلید جدید ساخته شود؟',
                    Button::to(Words::yes('کلید جدید ساخته شود'), self::ID, 'rekey'),
                    Button::to(Words::CANCEL, self::ID, self::VIEW),
                    'آدرس API فعلی بلافاصله از کار می‌افتد و وب‌سایت باید آدرس جدید را بگیرد.',
                    danger: true,
                );
            case 'rekey':
                $values = WebsiteSource::flat($admin->api->post('/website/key'));
                $admin->notice('✅ کلید جدید ساخته شد؛ آدرس تازه را به وب‌سایت بدهید.');

                return $this->card($admin, $values);
        }

        return parent::action($admin, $action, $args, $values);
    }
}
