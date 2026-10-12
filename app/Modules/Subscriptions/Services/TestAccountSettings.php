<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The free test accounts' rules (the `test_accounts` group, off by default): the quota — how many tests a customer gets
 * and over how many days, the shop's cap a day, newcomers only — and the clean-up of the tests that ended. What a test
 * gets is each server's (TestServers).
 */
final class TestAccountSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;
    private readonly Number $perUser;
    private readonly Number $periodDays;
    private readonly Number $dailyLimit;
    private readonly Toggle $newcomersOnly;
    private readonly Toggle $cleanup;
    private readonly Number $cleanupHours;
    private readonly Number $unusedDays;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('test_enabled', 'test.enabled', default: false);
        $this->perUser = new Number('test_per_user', 'test.per_user', default: 1, label: 'تعداد اکانت تست هر مشتری', min: 1, max: 100);
        $this->periodDays = new Number('test_period_days', 'test.period_days', default: 0, label: 'دوره سهمیه', min: 0, max: 365, unit: 'روز');
        $this->dailyLimit = new Number('test_daily_limit', 'test.daily_limit', default: 0, label: 'سقف روزانه فروشگاه', min: 0, max: 100_000);
        $this->newcomersOnly = new Toggle('test_newcomers_only', 'test.newcomers_only', default: false);
        $this->cleanup = new Toggle('test_cleanup', 'test.cleanup', default: true);
        $this->cleanupHours = new Number('test_cleanup_hours', 'test.cleanup_hours', default: 24, label: 'فاصله حذف', min: 0, max: 720, unit: 'ساعت');
        $this->unusedDays = new Number('test_unused_days', 'test.unused_days', default: 7, label: 'حذف اکانت وصل‌نشده', min: 0, max: 365, unit: 'روز');
    }

    public function groups(): array
    {
        return [new Form('test_accounts', [
            $this->enabled,
            $this->perUser,
            $this->periodDays,
            $this->dailyLimit,
            $this->newcomersOnly,
            $this->cleanup,
            $this->cleanupHours,
            $this->unusedDays,
        ])];
    }

    /** Whether the shop gives tests at all (each server has its own switch too). */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** How many tests a customer may take: ever, or within any periodDays(). */
    public function perUser(): int
    {
        return $this->settings->read($this->perUser);
    }

    /** The window perUser() counts over, in days; 0 — for good. */
    public function periodDays(): int
    {
        return $this->settings->read($this->periodDays);
    }

    /** The most tests the shop gives in one day of its clock; 0 — no cap. */
    public function dailyLimit(): int
    {
        return $this->settings->read($this->dailyLimit);
    }

    /** Whether only customers who never bought a service (with a price) may take one. */
    public function newcomersOnly(): bool
    {
        return $this->settings->read($this->newcomersOnly);
    }

    /** Whether the tests that ended are removed from their panels by themselves. */
    public function cleansUp(): bool
    {
        return $this->settings->read($this->cleanup);
    }

    /** How long an ended test is kept before it is removed, in hours; 0 — at once. */
    public function cleanupHours(): int
    {
        return $this->settings->read($this->cleanupHours);
    }

    /** After how many days a test never connected is removed; 0 — never (its term has not started). */
    public function unusedDays(): int
    {
        return $this->settings->read($this->unusedDays);
    }
}
