<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Support\Persian;

/**
 * What restoring a shop's backup changes for what is gone since it was made (Restorer::analyse()): the ids each of its
 * references (BackupTables::SHOP_REFERENCES, by index) names that are gone, and the rows that leaves out or clears.
 */
final readonly class BackupAnalysis
{
    /**
     * @param array<int, list<string>> $missing
     * @param array<'drop'|'null', array<string, int>> $counts Rows left out (`drop`) or cleared (`null`), by table
     */
    public function __construct(
        public array $missing,
        public array $counts,
    ) {}

    /**
     * What the admin is told before restoring, a line each.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $words = [
            'plan_servers' => 'سرور در پلن‌ها، روی سرورهایی که دیگر نیستند',
            'plan_server_inbounds' => 'اینباند در پلن‌ها، که دیگر نیستند',
            'orders' => 'سفارش، که سرورشان دیگر نیست و بی‌سرور بازگردانده می‌شوند',
            'test_accounts' => 'اکانت تست، که سرورشان دیگر نیست و بی‌سرور بازگردانده می‌شوند',
            'users' => 'مشتری، که سطح نمایندگی‌شان دیگر نیست و بدون آن بازگردانده می‌شوند',
        ];
        $warnings = [];
        foreach (['drop' => 'کنار گذاشته می‌شود: %s %s.', 'null' => 'تغییر می‌کند: %s %s.'] as $rule => $sentence) {
            foreach ($this->counts[$rule] ?? [] as $table => $rows) {
                $warnings[] = sprintf($sentence, Persian::number($rows), $words[$table] ?? $table);
            }
        }

        return $warnings;
    }
}
