<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\FieldSpec;
use App\Core\Scheduling\Intervals;
use App\Modules\Bots\Models\Bot;
use App\Support\Input;

/**
 * «زمان‌بندی کارها», the owner's — one for the whole installation, kept with the main shop, since one scheduler runs every
 * shop's work: how often each scheduled task runs (Intervals — bootstrap/schedule.php's minutes the defaults), and the
 * times the tasks decide by: when an unpaid order is dropped, how the online gateways are asked about a payment, and how
 * long the notices, the tickets' pictures and the report group's messages are kept. Each has its default, the value the
 * shop ran with before it was a setting. What keeps the shop safe stays fixed: how long an update is remembered so one
 * Telegram sends again runs once, how long a checkout's request key answers again, when a delivery left half-way counts
 * as stuck, and how long a test account's claim may wait.
 */
final class ScheduleSettings implements Intervals
{
    /** The longest a task may wait between two runs: a week, in minutes. */
    public const EVERY_MAX = 7 * 24 * 60;

    /** The times' defaults: what the shop ran with before they were settings. */
    public const ORDER_HOURS = 48;
    public const CHECK_MINUTES = 3;
    public const LATE_HOURS = 2;
    public const LATE_CHECK_MINUTES = 15;
    public const REVIEW_HOURS = 48;
    public const NOTICE_DAYS = 180;
    public const PICTURE_DAYS = 30;
    public const REPORT_DAYS = 7;
    public const REPORT_WAIT_HOURS = 24;

    /** What each scheduled task is called and does, by its key (Scheduler::key()), as the owner reads it. */
    public const TASKS = [
        'auto_approve_receipts' => ['تایید خودکار رسیدها', 'رسید کارت به کارتی که تا پایان مهلت بررسی روش پرداختش بررسی نشده، خودکار تایید می‌شود.'],
        'online_payments' => ['بررسی پرداخت‌های آنلاین', 'وضعیت پرداخت‌های آنلاینی که هنوز تایید نشده‌اند از درگاه پرسیده می‌شود، اگر خبر درگاه به فروشگاه نرسیده باشد.'],
        'resume_deliveries' => ['ادامه تحویل‌های نیمه‌کاره', 'سفارش پرداخت‌شده‌ای که تحویلش با قطع شدن برنامه نیمه‌کاره مانده، دوباره تحویل داده می‌شود.'],
        'expire_orders' => ['کنار گذاشتن سفارش‌های رهاشده', 'سفارش پرداخت‌نشده‌ای که مدتی دست نخورده کنار گذاشته می‌شود؛ سفارشی که با کد تخفیف ساخته شده، با تمام شدن مهلت کد.'],
        'send_broadcasts' => ['ارسال پیام‌های همگانی', 'هر بار، دسته‌ای از گیرندگان پیام همگانی در حال ارسال آن را می‌گیرند.'],
        'auto_renew' => ['تمدید خودکار', 'سرویس‌هایی که تمدید خودکارشان روشن است و به موعدشان نزدیک شده‌اند، از کیف پول تمدید می‌شوند.'],
        'next_period' => ['شروع دوره تمدیدشده', 'وقتی دوره پرداخت‌شده سرویس تمام شود، دوره تمدیدشده بعدی روی پنل شروع می‌شود.'],
        'grants' => ['افزودن زمان و حجم و هدیه همگانی', 'کارهای «افزودن زمان و حجم» و «هدیه همگانی» که صفحه‌ای رویشان کار نمی‌کند، دسته به دسته ادامه پیدا می‌کنند.'],
        'migrations' => ['انتقال سرویس‌ها', 'انتقال سرویس‌ها به سرور دیگر که صفحه‌ای رویش کار نمی‌کند، دسته به دسته ادامه پیدا می‌کند.'],
        'sync_subscriptions' => ['همگام‌سازی سرویس‌ها', 'مصرف، پایان و لینک همه سرویس‌های فعال از پنل‌ها خوانده می‌شود؛ برای هر سرور یک درخواست.'],
        'send_reminders' => ['یادآوری‌ها', 'به سرویس‌هایی که به پایان نزدیک شده‌اند یا حجمشان کم مانده یادآوری فرستاده می‌شود.'],
        'cleanup_services' => ['حذف سرویس‌های تمام‌شده', 'سرویس‌های تمام‌شده و اکانت‌های تست، طبق قوانین هر فروشگاه، از پنل‌ها حذف می‌شوند.'],
        'agency_standing' => ['شرایط نمایندگی', 'پایان نمایندگی و حداقل خرید ماهانه نماینده‌ها بررسی می‌شود: هشدار پیش از موعد، و توقف فروش وقتی شرط برقرار نیست.'],
        'prune_updates' => ['پاک‌سازی سابقه پیام‌های ربات', 'سابقه پیام‌هایی که ربات گرفته، بعد از گذشتن زمانی که تلگرام ممکن است دوباره بفرستدشان، پاک می‌شود.'],
        'prune_accounts' => ['پاک‌سازی حساب‌های وب‌سایت', 'ورودهای تمام‌شده وب‌سایت، کدهای ورود منقضی و اعلان‌های قدیمی پاک می‌شوند.'],
        'prune_tickets' => ['پاک‌سازی تیکت‌ها', 'تصویرهای تیکت‌های بسته‌شده و گزارش‌های قدیمی تیکت‌ها در گروه گزارش‌ها پاک می‌شوند.'],
        'nightly_draw' => ['قرعه‌کشی شبانه', 'بعد از رسیدن ساعت قرعه‌کشی، قرعه‌کشی همان روز یک بار انجام می‌شود.'],
        'send_backups' => ['ارسال پشتیبان به گروه', 'فایل پشتیبان به تاپیک «پشتیبان‌گیری» گروه گزارش‌های هر فروشگاه فرستاده می‌شود: گروه فروشگاه اصلی پشتیبان کل نصب را می‌گیرد، گروه هر نماینده پشتیبان فروشگاه خودش را.'],
        'check_releases' => ['بررسی نسخه تازه', 'آخرین نسخه AmoBot از GitHub خوانده می‌شود تا داشبورد بگوید نسخه تازه‌ای آمده است.'],
        'send_reports' => ['ارسال گزارش‌ها', 'گزارش‌هایی که در صف مانده‌اند، با سرعتی که تلگرام می‌پذیرد، به گروه گزارش‌ها فرستاده می‌شوند.'],
    ];

    private readonly Number $orderHours;
    private readonly Number $checkMinutes;
    private readonly Number $lateHours;
    private readonly Number $lateCheckMinutes;
    private readonly Number $reviewHours;
    private readonly Number $noticeDays;
    private readonly Number $pictureDays;
    private readonly Number $reportDays;
    private readonly Number $reportWaitHours;

    public function __construct(private readonly Settings $settings)
    {
        $this->orderHours = self::number('expire_orders_hours', self::ORDER_HOURS, 'مهلت سفارش پرداخت‌نشده', 1, 30 * 24, 'ساعت', 'سفارش پرداخت‌نشده‌ای که این مدت دست نخورده کنار گذاشته می‌شود؛ سفارشی که پولش در دست پشتیبانی است هیچ‌وقت.');
        $this->checkMinutes = self::number('online_payments_check_minutes', self::CHECK_MINUTES, 'فاصله پرسیدن، صفحه پرداخت باز', 1, 60, 'دقیقه', 'تا وقتی صفحه پرداخت درگاه باز است، هر این چند دقیقه یک بار از درگاه پرسیده می‌شود.');
        $this->lateHours = self::number('online_payments_late_hours', self::LATE_HOURS, 'پرسیدن پس از بسته شدن صفحه', 0, 72, 'ساعت', 'تا این مدت پس از بسته شدن صفحه پرداخت هنوز پرسیده می‌شود؛ درگاه ممکن است پول را دیرتر بگیرد.');
        $this->lateCheckMinutes = self::number('online_payments_late_check_minutes', self::LATE_CHECK_MINUTES, 'فاصله پرسیدن، صفحه بسته', 1, 24 * 60, 'دقیقه', 'پس از بسته شدن صفحه پرداخت، هر این چند دقیقه یک بار پرسیده می‌شود.');
        $this->reviewHours = self::number('online_payments_review_hours', self::REVIEW_HOURS, 'مهلت پول تسویه‌نشده', 1, 30 * 24, 'ساعت', 'پرداختی که درگاه پولی از آن دارد ولی تسویه نکرده، تا این مدت پس از بسته شدن صفحه پرسیده می‌شود و بعد برای پشتیبانی نگه داشته می‌شود.', atLeast: $this->lateHours);
        $this->noticeDays = self::number('notices_keep_days', self::NOTICE_DAYS, 'نگه‌داری اعلان‌های وب‌سایت', 1, 3650, 'روز', 'اعلان‌های مشتری در وب‌سایت تا این مدت نگه داشته و بعد پاک می‌شوند.');
        $this->pictureDays = self::number('ticket_pictures_keep_days', self::PICTURE_DAYS, 'نگه‌داری تصویرهای تیکت بسته', 1, 3650, 'روز', 'تصویرهایی که در تیکت‌ها بارگذاری شده، تا این مدت پس از بسته شدن تیکت نگه داشته می‌شوند.');
        $this->reportDays = self::number('reports_keep_days', self::REPORT_DAYS, 'نگه‌داری گزارش‌های فرستاده‌شده', 1, 365, 'روز', 'گزارش‌های گروه تا این مدت نگه داشته می‌شوند تا پاسخ به آن‌ها شناخته شود؛ گزارش‌های تیکتی که باز است همیشه.');
        $this->reportWaitHours = self::number('reports_wait_hours', self::REPORT_WAIT_HOURS, 'مهلت ارسال گزارش', 1, 7 * 24, 'ساعت', 'گزارشی که این مدت در صف مانده و فرستاده نشده، کنار گذاشته می‌شود.');
    }

    /** The task's interval as the owner set it, minutes; `$default` while they set none (or set what no field takes). */
    public function minutes(string $key, int $default): int
    {
        $minutes = Input::integerOf($this->settings->get(self::intervalKey($key), null, Bot::MAIN));

        return $minutes !== null && $minutes >= 1 && $minutes <= self::EVERY_MAX ? $minutes : $default;
    }

    /** The field a task's interval is set by, its default `$default` minutes (the schedule's). */
    public function intervalField(string $key, int $default): Number
    {
        return new Number($key . '_minutes', self::intervalKey($key), $default, label: 'فاصله اجرای «' . (self::TASKS[$key][0] ?? $key) . '»', min: 1, max: self::EVERY_MAX, unit: 'دقیقه', spec: new FieldSpec('فاصله اجرا'));
    }

    /**
     * The fields a task decides by, beside its interval, by its key: none for most.
     *
     * @return list<Number>
     */
    public function timingFields(string $key): array
    {
        return match ($key) {
            'expire_orders' => [$this->orderHours],
            'online_payments' => [$this->checkMinutes, $this->lateHours, $this->lateCheckMinutes, $this->reviewHours],
            'prune_accounts' => [$this->noticeDays],
            'prune_tickets' => [$this->pictureDays],
            'send_reports' => [$this->reportDays, $this->reportWaitHours],
            default => [],
        };
    }

    /** Hours an unpaid order nobody touched waits before it is dropped (ExpireOrdersTask). */
    public function orderHours(): int
    {
        return $this->read($this->orderHours);
    }

    /** Minutes between two asks about an online payment whose page takes money (OnlinePaymentsTask). */
    public function checkMinutes(): int
    {
        return $this->read($this->checkMinutes);
    }

    /** Hours after its page closed that an online payment is still asked about. */
    public function lateHours(): int
    {
        return $this->read($this->lateHours);
    }

    /** Minutes between two asks about an online payment whose page closed. */
    public function lateCheckMinutes(): int
    {
        return $this->read($this->lateCheckMinutes);
    }

    /** Hours after its page closed that one with money in at its gateway, unsettled, is asked about before support holds it. */
    public function reviewHours(): int
    {
        // Never before its late hours are over: a setting saved alone could not have been, but the two are read apart.
        return max($this->read($this->reviewHours), $this->lateHours());
    }

    /** Days a website notice is kept (PruneAccountsTask). */
    public function noticeDays(): int
    {
        return $this->read($this->noticeDays);
    }

    /** Days an uploaded picture of a closed ticket is kept after it closed (PruneTicketsTask). */
    public function pictureDays(): int
    {
        return $this->read($this->pictureDays);
    }

    /** Days a report group's message sent, or given up on, is kept (SendReportsTask, PruneTicketsTask). */
    public function reportDays(): int
    {
        return $this->read($this->reportDays);
    }

    /** Hours a report may wait in the queue before it is given up on (SendReportsTask). */
    public function reportWaitHours(): int
    {
        return $this->read($this->reportWaitHours);
    }

    private function read(Number $field): int
    {
        return $this->settings->read($field, Bot::MAIN);
    }

    private static function intervalKey(string $key): string
    {
        return "schedule.{$key}.minutes";
    }

    private static function number(string $name, int $default, string $label, int $min, int $max, string $unit, string $hint, ?Number $atLeast = null): Number
    {
        return new Number($name, 'schedule.' . $name, $default, $label, $min, $max, $unit, new FieldSpec($label, hint: $hint), $atLeast);
    }
}
