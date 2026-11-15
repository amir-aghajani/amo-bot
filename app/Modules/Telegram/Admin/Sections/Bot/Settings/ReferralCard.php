<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Support\Money;

/**
 * «زیرمجموعه‌گیری» (`referral`): the program's switch and its four parts — the commission (renewals at a rate of their
 * own), the start gift both sides get, the plan given every so many buying referrals (picked among the plans), and the
 * fake-referral alerts — shown while it runs, as the panel holds them while not; and, while it runs with no button for
 * it on the bot's menu, that customers have no way to it.
 */
final class ReferralCard extends BotCard
{
    public const ID = 'bsf';

    protected function group(): string
    {
        return 'referral';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🤝 زیرمجموعه‌گیری';
    }

    protected function intro(): string
    {
        return 'هر مشتری در صفحه «زیرمجموعه‌گیری» ربات لینک دعوت خودش را دارد. کسی که اولین بار با آن لینک وارد ربات شود زیرمجموعه اوست و از پرداخت‌هایش، پورسانت به کیف پول معرف اضافه می‌شود؛ کسی که از قبل در ربات ثبت شده باشد، با هیچ لینکی زیرمجموعه کسی نمی‌شود.';
    }

    protected function fields(): array
    {
        $on = static fn(FieldDef $field): FieldDef => $field->when('referral_enabled', [true]);

        return [
            FieldDef::toggle('referral_enabled', 'زیرمجموعه‌گیری فعال باشد', 'خاموش که باشد، دکمه «زیرمجموعه‌گیری» از منوی ربات برداشته می‌شود، لینک‌ها کسی را زیرمجموعه نمی‌کنند و پرداختی پورسانت یا هدیه‌ای ندارد. با روشن شدن دوباره، دکمه سر جایش برمی‌گردد و زیرمجموعه‌ها و پورسانت‌های قبلی سر جایشان می‌مانند.'),
            $on(FieldDef::number('referral_rate', 'درصد پورسانت', 'درصد', 'پورسانت: از پولی که زیرمجموعه واقعا واریز می‌کند، سهمی به کیف پول معرف اضافه می‌شود؛ بین ۱ تا ۱۰۰ درصد.')),
            $on(FieldDef::number('referral_renewal_rate', 'درصد پورسانت تمدید', 'درصد', 'پرداخت تمدید سرویس به جای درصد بالا این درصد را دارد؛ ۰ یعنی بدون پورسانت.')),
            $on(FieldDef::toggle('referral_first_only', 'فقط اولین پرداخت هر زیرمجموعه', 'روشن: فقط اولین پرداخت موفق هر زیرمجموعه پورسانت دارد. خاموش: هر پرداخت موفق او.')),
            $on(FieldDef::money('referral_start_referrer', 'هدیه شروع معرف', 'هدیه شروع: به ازای هر زیرمجموعه یک بار، مبلغی به کیف پول معرف و مبلغی به کیف پول خود زیرمجموعه اضافه می‌شود و هر دو در تلگرام یا با ایمیل خبردار می‌شوند. ۰ یعنی بدون هدیه.')),
            $on(FieldDef::money('referral_start_invitee', 'هدیه شروع زیرمجموعه', 'مبلغ به تومان؛ ۰ یعنی بدون هدیه.')),
            $on(FieldDef::choice('referral_start_when', 'زمان هدیه شروع', ['purchase' => 'با اولین خرید', 'join' => 'با عضویت'], '«با اولین خرید»: وقتی اولین خرید زیرمجموعه پرداخت شد — با هر روش، کیف پول هم؛ سرویس هدیه خرید حساب نمی‌شود. حساب‌هایی که فقط برای هدیه ساخته شوند چیزی نمی‌گیرند.' . "\n" . '«با عضویت»: همان لحظه که زیرمجموعه با لینک وارد ربات یا وب‌سایت می‌شود.')),
            // The plan before its count, as each is saved on its own: a count above 0 needs a plan.
            $on(FieldDef::choice('referral_reward_plan_id', 'جایزه دعوت: پلن جایزه', [], 'سرویس جایزه روی اولین سروری ساخته می‌شود که این پلن را می‌فروشد. جایزه دعوت پلن را لازم دارد؛ اول پلن را انتخاب کنید، بعد تعداد را.'))->nullable(),
            $on(FieldDef::number('referral_reward_every', 'جایزه دعوت: به ازای هر چند زیرمجموعه خریدار', 'زیرمجموعه', 'هر بار که تعداد زیرمجموعه‌های خریدار یک معرف به مضرب تازه‌ای از این عدد برسد، یک سرویس از پلن جایزه رایگان به او داده می‌شود. ۰ یعنی بدون جایزه.')),
            $on(FieldDef::number('referral_alert_burst', 'هشدار: عضویت پشت سر هم', 'زیرمجموعه', 'هشدار زیرمجموعه‌گیری فیک: این تعداد زیرمجموعه با یک لینک در بازه هشدار. معرفی که زیرمجموعه‌هایش ساختگی به نظر برسند، در گروه گزارش‌ها (بخش کاربران جدید) و صفحه «زیرمجموعه‌گیری» هشدار می‌گیرد. تا پشتیبانی هشدار را رفع نکند، هدیه شروع و جایزه دعوت به او داده نمی‌شود — و بعد از رفع هم داده نمی‌شود؛ پورسانت‌ها مثل قبل است. ۰ یعنی بدون آن هشدار.')),
            $on(FieldDef::number('referral_alert_minutes', 'هشدار: بازه هشدار', 'دقیقه', 'در هر بازه، هر نوع هشدار یک بار.')),
            $on(FieldDef::number('referral_alert_idle', 'هشدار: زیرمجموعه بدون خرید', 'زیرمجموعه', 'این تعداد زیرمجموعه که هیچ‌کدام خرید نکرده‌اند؛ ۰ یعنی بدون آن هشدار.')),
        ];
    }

    protected function notes(): array
    {
        return ['پورسانت فقط از پولی حساب می‌شود که واقعا واریز شده است: پرداخت کارت به کارت یا درگاه، برای خرید، تمدید یا شارژ کیف پول. خرید از کیف پول دوباره پورسانت ندارد، چون پولش وقتی کیف پول شارژ شد حساب شده است. برگشت وجه شارژ کیف پول، که پولش از فروشگاه بیرون می‌رود، پورسانتش را از کیف پول معرف پس می‌گیرد؛ اگر معرف آن را خرج کرده باشد، برگشت وجه انجام نمی‌شود. برگشت وجه خرید یا تمدید به کیف پول مشتری است و پولش در فروشگاه می‌ماند، پس پورسانتش هم می‌ماند. زیرمجموعه‌ها، پورسانت‌ها و هشدارها در «زیرمجموعه‌گیری» دیده می‌شوند.'];
    }

    protected function warnings(Admin $admin, array $values): array
    {
        if (($values['referral_enabled'] ?? false) !== true || KeyboardScreen::hasButton($admin, 'affiliates')) {
            return [];
        }

        return ['دکمه «زیرمجموعه‌گیری» در منوی ربات نیست، پس مشتری‌ها به صفحه لینک دعوتشان راهی ندارند. دکمه را در «⌨️ کیبوردها» اضافه کنید.'];
    }

    protected function picks(Admin $admin, FieldDef $field, array $values): ?array
    {
        if ($field->name !== 'referral_reward_plan_id') {
            return null;
        }
        $plans = [];
        foreach ((array) ($admin->api->get('/plans')['plans'] ?? []) as $plan) {
            if (is_array($plan) && is_int($plan['id'] ?? null)) {
                $plans[(string) $plan['id']] = (string) ($plan['name'] ?? '') . (($plan['is_active'] ?? true) === false ? ' (غیرفعال؛ فقط برای هدیه)' : '');
            }
        }

        return $plans;
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        if ($field->name === 'referral_reward_plan_id') {
            if (!is_int($value)) {
                return 'انتخاب نشده';
            }
            $name = $this->here === null ? null : ($this->picks($this->here, $field, [])[$value] ?? null);

            return Fmt::e($name ?? 'پلن ' . Fmt::plain(Fmt::id($value)));
        }
        if (in_array($field->name, ['referral_start_referrer', 'referral_start_invitee'], true) && (float) $value <= 0) {
            return 'بدون هدیه';
        }

        return null;
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        $rate = (int) ($values['referral_rate'] ?? 0);
        $every = (int) ($values['referral_reward_every'] ?? 0);

        return match ($field->name) {
            'referral_rate' => $rate > 0 && $rate <= 100 ? 'از پرداخت ' . Money::format(100000) . '، ' . Money::format(intdiv(100000 * $rate, 100)) . ' به کیف پول معرف اضافه می‌شود.' : null,
            'referral_renewal_rate' => (int) ($values['referral_renewal_rate'] ?? 0) === 0 ? 'تمدید سرویس پورسانت ندارد.' : null,
            'referral_reward_every' => $every > 0 ? 'با خرید ' . Fmt::number($every) . 'امین، ' . Fmt::number($every * 2) . 'امین و … زیرمجموعه' : 'بدون جایزه',
            default => null,
        };
    }
}
