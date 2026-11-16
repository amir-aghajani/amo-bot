<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «تمدید سرویس» (`renewal`): what a renewal does with the traffic left, from when a running service may be renewed
 * («آستانه مجاز تمدید») and the ways a renewal adds its plan — automatic renewal's method one of those switched on: a
 * method switched off that was it moves it to the first still on, in the same save, as the panel's card does. «تمدید
 * خودکار» is its own card (AutoRenewCard).
 */
final class RenewalCard extends BotCard
{
    public const ID = 'bsr';

    public const TITLE = '🔁 تمدید سرویس';

    /** Each method's switch and name (the panel's RENEWAL_METHOD), in the bot's order. */
    private const METHODS = [
        'extend' => ['renew_extend', 'تمدید پلن فعلی'],
        'reset_traffic' => ['renew_reset_traffic', 'ریست حجم + افزایش روز'],
        'reset_time' => ['renew_reset_time', 'ریست زمان + انتقال حجم'],
    ];

    private const ALWAYS = 'با هر دو ۰، مشتری هر وقت بخواهد می‌تواند تمدید کند.';

    protected function group(): string
    {
        return 'renewal';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function intro(): string
    {
        return 'قانون تمدید، چه مشتری خودش تمدید کند و چه تمدید خودکار: حجم باقی‌مانده، از کی مشتری می‌تواند سرویسش را تمدید کند، و روش‌هایی که تمدید با آن‌ها انجام می‌شود.';
    }

    protected function fields(): array
    {
        return $this->described([
            FieldDef::toggle('carry_traffic', 'حجم باقی‌مانده به دوره جدید منتقل شود', 'برای روش «تمدید پلن فعلی». روشن: حجم مصرف‌نشده دوره فعلی به حجم دوره جدید اضافه می‌شود. خاموش: حجم مصرف‌نشده تا پایان دوره فعلی قابل استفاده است و بعد از آن صفر می‌شود؛ دوره جدید با حجم پلن شروع می‌شود. تمدید زودتر از پایان دوره، چیزی از حجم فعلی مشتری کم نمی‌کند.'),
            FieldDef::number('renew_window_days', 'روز مانده به پایان', 'روز', 'بین ۰ تا ۳۶۵ روز؛ ۰ یعنی بدون این شرط.'),
            FieldDef::number('renew_window_percent', 'درصد حجم مانده', 'درصد', 'بین ۰ تا ۱۰۰ درصد؛ ۰ یعنی بدون این شرط.'),
            FieldDef::toggle('renew_extend', 'تمدید پلن فعلی', 'روزهای پلن به روزهای مانده و حجم پلن به حجم سرویس اضافه می‌شود؛ حجم مانده طبق «حجم باقی‌مانده به دوره جدید منتقل شود» به دوره جدید می‌رود یا با پایان دوره فعلی از بین می‌رود.'),
            FieldDef::toggle('renew_reset_traffic', 'ریست حجم + افزایش روز', 'روزهای پلن به روزهای مانده اضافه می‌شود و حجم از نو با حجم پلن شمرده می‌شود؛ حجم مانده از بین می‌رود.'),
            FieldDef::toggle('renew_reset_time', 'ریست زمان + انتقال حجم', 'مدت سرویس از همان لحظه با مدت پلن از نو شروع می‌شود و روزهای مانده از بین می‌رود؛ حجم پلن به حجم مانده اضافه می‌شود.'),
            FieldDef::choice('renew_default_method', 'روش تمدید خودکار', array_map(static fn(array $method): string => $method[1], self::METHODS), 'تمدید خودکار از کیف پول با این روش انجام می‌شود؛ یکی از روش‌های روشن. با «ریست زمان + انتقال حجم» تمدید تا آخرین نیم‌ساعت پیش از پایان سرویس صبر می‌کند تا از مدت پرداخت‌شده بیش از همین نیم‌ساعت از بین نرود؛ اگر موجودی کیف پول کافی نباشد، مشتری همچنان از «چند روز پیش از پایان سرویس» خبردار می‌شود.'),
        ]);
    }

    /** The panel's parts: from when a service may be renewed, and the ways a renewal adds its plan. */
    protected function parts(): array
    {
        return [
            ['⏳ آستانه مجاز تمدید', ['renew_window_days', 'renew_window_percent']],
            ['🔀 روش‌های تمدید', ['renew_extend', 'renew_reset_traffic', 'renew_reset_time', 'renew_default_method']],
        ];
    }

    protected function notes(): array
    {
        return [
            'آستانه مجاز تمدید: تا سرویس فعال به این آستانه نرسیده، مشتری در ربات و سایت نمی‌تواند تمدیدش کند و می‌بیند از کی می‌تواند. با هر دو، هر کدام زودتر برسد؛ با هر دو صفر، هر وقت بخواهد. سرویس تمام‌شده همیشه تمدید می‌شود، و سرویسی که هنوز وصل نشده یا پایان ندارد فقط با حجم. تمدید خودکار روزهای خودش را دارد.',
            'روش‌های تمدید: با بیش از یک روش روشن، مشتری در صفحه پرداخت تمدید (ربات و سایت) یکی را انتخاب می‌کند و تاریخ پایان و حجم بعد از تمدید را با همان روش می‌بیند؛ صفحه با اولین روش روشن باز می‌شود. قیمت تمدید در همه روش‌ها یکی است.',
        ];
    }

    protected function picks(Admin $admin, FieldDef $field, array $values): ?array
    {
        if ($field->name !== 'renew_default_method') {
            return null;
        }
        $on = [];
        foreach (self::METHODS as $method => [$switch, $label]) {
            if (($values[$switch] ?? false) === true) {
                $on[$method] = $label;
            }
        }

        return $on;
    }

    protected function toggled(Admin $admin, FieldDef $field, array $values, bool $on): View
    {
        if (!in_array($field->name, array_column(self::METHODS, 0), true)) {
            return parent::toggled($admin, $field, $values, $on);
        }
        // A method switched: automatic renewal's stays one of those on, the first that is when it is not.
        $values[$field->name] = $on;
        $kept = array_keys(array_filter(self::METHODS, static fn(array $method): bool => ($values[$method[0]] ?? false) === true));
        if ($kept !== [] && !in_array($values['renew_default_method'] ?? null, $kept, true)) {
            $values['renew_default_method'] = $kept[0];
        }

        return $this->store($admin, $field, $values, $on);
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        $days = (int) ($values['renew_window_days'] ?? 0);
        $percent = (int) ($values['renew_window_percent'] ?? 0);

        return match ($field->name) {
            'renew_window_days' => match (true) {
                $days === 0 && $percent === 0 => self::ALWAYS,
                $days === 0 => '۰: روزهای مانده تمدید را باز نمی‌کند؛ فقط درصد حجم مانده بازش می‌کند.',
                default => 'از ' . Fmt::plain(Fmt::number($days)) . ' روز مانده به پایان سرویس، مشتری می‌تواند آن را تمدید کند.',
            },
            'renew_window_percent' => match (true) {
                $percent === 0 && $days === 0 => self::ALWAYS,
                $percent === 0 => '۰: حجم مانده تمدید را باز نمی‌کند؛ فقط روزهای مانده بازش می‌کند.',
                default => 'وقتی ' . Fmt::plain(Fmt::number($percent)) . '٪ از حجم سرویس یا کمتر مانده باشد، مشتری می‌تواند آن را تمدید کند.',
            },
            default => null,
        };
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $view->row(Button::to(AutoRenewCard::TITLE, AutoRenewCard::ID));
    }
}
