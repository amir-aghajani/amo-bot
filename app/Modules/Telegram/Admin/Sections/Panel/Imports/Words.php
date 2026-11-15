<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * «انتقال از ربات دیگر»'s words (the panel's components/imports/words.ts): an import's phases, the stages it writes in,
 * the kinds it counts, why some things did not come — and the choices of its review: as they stand, and each switch of
 * «چه چیزهایی منتقل شود» worded from the survey.
 */
final class Words
{
    /** What the section is: what an import brings, and what it never touches. */
    public const ABOUT = 'مشتری‌ها، موجودی کیف پول‌ها، سرویس‌ها، پلن‌ها، کدهای تخفیف و هدیه، کانال‌ها و کارت‌های ربات قبلی‌تان را به فروشگاه اصلی بیاورید. روی پنل‌های VPN و ربات قبلی چیزی تغییر نمی‌کند.';

    /** An import's phase, as its badge says it. */
    public const PHASE = [
        'reading' => '🔄 در حال خواندن',
        'review' => '📝 آماده بررسی',
        'importing' => '⏳ در حال انتقال',
        'done' => '✅ انجام‌شده',
        'failed' => '⚠️ ناموفق',
        'cancelled' => '✖️ لغوشده',
    ];

    /** The stages an import writes in, in their order. */
    public const STAGES = [
        'servers' => 'سرورها',
        'groups' => 'گروه‌های مشتری',
        'customers' => 'مشتری‌ها',
        'referrals' => 'معرفی‌ها',
        'plans' => 'پلن‌ها',
        'services' => 'سرویس‌ها',
        'discount_codes' => 'کدهای تخفیف',
        'gift_codes' => 'کدهای هدیه',
        'channels' => 'کانال‌های اجباری',
        'cards' => 'کارت‌های بانکی',
    ];

    /** The kinds an import counts what it did with, in the order its report lists them. */
    public const COUNT_KINDS = [
        'customers' => 'مشتری‌ها',
        'balances' => 'موجودی کیف پول‌ها',
        'groups' => 'گروه‌های مشتری',
        'servers' => 'سرورها',
        'categories' => 'دسته‌بندی‌ها',
        'plans' => 'پلن‌ها',
        'services' => 'سرویس‌ها',
        'discount_codes' => 'کدهای تخفیف',
        'gift_codes' => 'کدهای هدیه',
        'channels' => 'کانال‌های اجباری',
        'cards' => 'کارت‌های بانکی',
    ];

    /** A thing of the other bot's, by the kind a problem names it. */
    private const THING = [
        'customers' => 'مشتری',
        'balances' => 'موجودی',
        'groups' => 'گروه',
        'servers' => 'پنل',
        'categories' => 'دسته‌بندی',
        'plans' => 'پلن',
        'services' => 'سرویس',
        'discount_codes' => 'کد تخفیف',
        'gift_codes' => 'کد هدیه',
        'channels' => 'کانال',
        'cards' => 'کارت',
    ];

    /** The reasons only counted: the owner's own choice, or nothing to act on — no row to list. */
    public const COUNTED = ['reseller', 'panel_skipped', 'not_chosen', 'used_up', 'expired', 'ended', 'removed'];

    /** Where a bot's own backup file is found, by its migrator. */
    public const BACKUP_FROM = [
        'faoxima' => "فایل \u{2068}backup.zip\u{2069} که ربات \u{2068}Faoxima\u{2069} هر چند ساعت به کانال گزارش‌هایش می‌فرستد؛ آخرین آن را از همان کانال بردارید و همین‌جا بفرستید.",
        'mirzabot' => "فایل \u{2068}backup_تاریخ.sql\u{2069} که ربات \u{2068}MirzaBot\u{2069} هر چند ساعت به کانال گزارش‌هایش می‌فرستد (نه فایل‌های zip ربات‌های نماینده کنارش)؛ آخرین آن را از همان کانال بردارید و همین‌جا بفرستید.",
    ];

    /** Why a backup for good is taken with the old bot stopped. */
    public const FINAL_BACKUP = 'برای انتقال نهایی، اول ربات قبلی را خاموش کنید و بعد پشتیبانش را بگیرید؛ آنچه بعد از پشتیبان در ربات قبلی عوض شود (کدهای استفاده‌شده، خریدها، موجودی‌ها) منتقل نمی‌شود.';

    /** A thing by the kind a problem names it — the kind as it is when there is no word for it. */
    public static function thing(string $kind): string
    {
        return self::THING[$kind] ?? $kind;
    }

    /**
     * Where the data is read from, as a sentence's end (HTML): «فایل backup.zip», «دیتابیس ربات».
     *
     * @param array<string, mixed> $import
     */
    public static function source(array $import): string
    {
        if (($import['source'] ?? '') === 'database') {
            return 'دیتابیس ربات';
        }

        return is_string($import['file_name'] ?? null) ? 'فایل ' . Fmt::ltr($import['file_name']) : 'فایل پشتیبان';
    }

    /**
     * GET /imports/{id}'s import.
     *
     * @return array<string, mixed>
     */
    public static function import(Admin $admin, int $id): array
    {
        return (array) ($admin->api->get('/imports/' . $id)['import'] ?? []);
    }

    /**
     * The bots an import may come from (GET /imports' migrators), by key.
     *
     * @return array<string, array{label: string, description: string, notes: list<string>, fields: list<array<string, mixed>>, traits: array<string, mixed>}>
     */
    public static function migrators(Admin $admin): array
    {
        return DraftForm::drivers(Json::rows($admin->api->get('/imports'), 'migrators'));
    }

    /**
     * The review's choices as they stand: the import's saved ones, else the API's proposal, else every panel left behind.
     *
     * @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    public static function choices(array $import): array
    {
        $choices = is_array($import['choices'] ?? null) ? $import['choices'] : (is_array($import['proposed'] ?? null) ? $import['proposed'] : null);
        if ($choices !== null) {
            return $choices;
        }
        $survey = Json::part($import, 'survey');
        $panels = [];
        foreach (Json::part($survey, 'panels') as $panel) {
            if (is_array($panel) && is_string($panel['key'] ?? null)) {
                $panels[$panel['key']] = ['action' => 'skip', 'server_id' => null];
            }
        }

        return ['panels' => $panels, 'balances' => true, 'banned' => true, 'phones' => true, 'referrals' => true, 'discounts' => true, 'groups' => true, 'admins' => false, 'resellers' => false, 'services' => true, 'tests' => true, 'plans' => true, 'plans_on' => false, 'discount_codes' => true, 'gift_codes' => true, 'channels' => false, 'cards' => true];
    }

    /**
     * The choices as the review shows them: a switch held off by the one it goes with is sent off.
     *
     * @param array<string, mixed> $choices
     * @return array<string, mixed>
     */
    public static function shown(array $choices): array
    {
        $choices['tests'] = Json::on($choices, 'services') && Json::on($choices, 'tests');
        $choices['plans_on'] = Json::on($choices, 'plans') && Json::on($choices, 'plans_on');
        $choices['panels'] = (object) (Json::part($choices, 'panels'));

        return $choices;
    }

    /**
     * What each part of «چه چیزهایی منتقل شود» offers, worded from the survey: [title, about, switches], each switch
     * [name, label, hint, needs (the switch it goes with) and its hint while that one is off].
     *
     * @param array<string, mixed> $survey
     * @return list<array{0: string, 1: string, 2: list<array{name: string, label: string, hint: string, needs?: array{0: string, 1: string}}>}>
     */
    public static function parts(array $survey): array
    {
        $counts = Json::part($survey, 'counts');
        $count = static fn(string $kind): string => Fmt::number((int) ($counts[$kind] ?? 0));
        $groups = [];
        foreach (Json::part($survey, 'groups') as $group) {
            if (is_array($group)) {
                $groups[] = (string) ($group['name'] ?? '') . ' (' . Fmt::number((int) ($group['customers'] ?? 0)) . ' نفر' . (Json::on($group, 'agent_pricing') ? '، قیمت نماینده' : '') . ')';
            }
        }
        $admins = [];
        foreach (Json::part($survey, 'admins') as $admin) {
            if (is_array($admin)) {
                $admins[] = (is_string($admin['username'] ?? null) && $admin['username'] !== '' ? '@' . $admin['username'] : (string) ($admin['telegram_id'] ?? '')) . (is_string($admin['role'] ?? null) && $admin['role'] !== '' ? ' (' . $admin['role'] . ')' : '');
            }
        }
        $customers = [
            ['name' => 'balances', 'label' => 'موجودی کیف پول', 'hint' => 'موجودی هر مشتری به کیف پولش اضافه می‌شود و بدهی، بدهی می‌ماند. ' . $count('balances') . ' مشتری موجودی دارند؛ جمع: ' . Fmt::plain(Fmt::money(is_scalar($survey['balance_total'] ?? null) ? (string) $survey['balance_total'] : '0')) . '.'],
            ['name' => 'banned', 'label' => 'مسدودی‌ها', 'hint' => 'مشتری‌هایی که در ربات قبلی مسدود بودند، این‌جا هم مسدود می‌شوند.'],
            ['name' => 'phones', 'label' => 'شماره موبایل‌ها', 'hint' => 'شماره موبایلی که مشتری در ربات قبلی فرستاده بود.'],
            ['name' => 'referrals', 'label' => 'معرف‌ها', 'hint' => 'هر مشتری زیرمجموعه همان کسی می‌شود که در ربات قبلی معرفش بود؛ هدیه معرفی داده نمی‌شود.'],
            ['name' => 'discounts', 'label' => 'تخفیف شخصی', 'hint' => 'درصد تخفیفی که برای هر مشتری جدا تعیین شده بود.'],
        ];
        if ($groups !== []) {
            $customers[] = ['name' => 'groups', 'label' => 'گروه‌ها', 'hint' => 'مشتری‌ها به این گروه‌ها می‌پیوندند؛ گروهی که نیست ساخته می‌شود: ' . implode('، ', $groups) . '.'];
        }
        if ($admins !== []) {
            $customers[] = ['name' => 'admins', 'label' => 'مدیرها', 'hint' => 'این افراد مدیر این فروشگاه هم می‌شوند: ' . implode('، ', $admins) . '.'];
        }
        if ((int) ($counts['resellers'] ?? 0) > 0) {
            $customers[] = ['name' => 'resellers', 'label' => 'مشتری‌های ربات‌های نماینده', 'hint' => $count('resellers') . ' مشتری که از ربات‌های نماینده‌های ربات قبلی خرید کرده بودند، با سرویس‌هایشان.'];
        }

        return [
            ['👥 مشتری‌ها', 'همه ' . $count('customers') . ' مشتری منتقل می‌شوند؛ مشتری‌ای که از قبل در فروشگاه هست، همان می‌ماند و فقط آنچه ندارد پر می‌شود. پیام خوش‌آمد یا گزارشی فرستاده نمی‌شود.', $customers],
            ['📡 سرویس‌ها و پلن‌ها', 'سرویس‌ها روی سرورهایی پیدا می‌شوند که برای پنل‌هایشان انتخاب کرده‌اید؛ سرویس پنلی که منتقل نمی‌شود نمی‌آید.', [
                ['name' => 'services', 'label' => 'سرویس‌ها', 'hint' => $count('services') . ' سرویس، همان‌طور که روی پنل هستند: همان لینک، همان حجم مصرف‌شده و همان تاریخ پایان.'],
                ['name' => 'tests', 'label' => 'اکانت‌های تست', 'hint' => $count('tests') . ' اکانت تست که هنوز روی پنل هستند.', 'needs' => ['services', 'با خاموش بودن «سرویس‌ها»، اکانت‌های تست هم منتقل نمی‌شوند.']],
                ['name' => 'plans', 'label' => 'پلن‌ها', 'hint' => $count('plans') . ' پلن با دسته‌بندی‌هایشان، روی سرورهایی که پنل‌هایشان به آن‌ها می‌رسد.'],
                ['name' => 'plans_on', 'label' => 'پلن‌ها روشن بیایند', 'hint' => 'خاموش بمانند تا قیمت‌ها و سرورهایشان را ببینید و خودتان روشن کنید.', 'needs' => ['plans', 'با خاموش بودن «پلن‌ها»، پلنی منتقل نمی‌شود.']],
            ]],
            ['🏷 کدها، کانال‌ها و کارت‌ها', 'کدی که نامش از قبل در فروشگاه هست، دوباره ساخته نمی‌شود و در گزارش می‌آید.', [
                ['name' => 'discount_codes', 'label' => 'کدهای تخفیف', 'hint' => $count('discount_codes') . ' کد تخفیف؛ کدی که استفاده‌هایش یا مهلتش تمام شده، نمی‌آید.'],
                ['name' => 'gift_codes', 'label' => 'کدهای هدیه', 'hint' => $count('gift_codes') . ' کد شارژ کیف پول؛ کدی که استفاده‌هایش یا مهلتش تمام شده، نمی‌آید.'],
                ['name' => 'channels', 'label' => 'کانال‌های اجباری', 'hint' => $count('channels') . ' کانال. ربات این فروشگاه باید در هر کانال مدیر باشد؛ وگرنه آن کانال اضافه نمی‌شود.'],
                ['name' => 'cards', 'label' => 'کارت‌های بانکی', 'hint' => $count('cards') . ' کارت، به روش پرداخت کارت به کارت؛ خاموش می‌آیند تا بررسی‌شان کنید و خودتان روشن کنید.'],
            ]],
        ];
    }
}
