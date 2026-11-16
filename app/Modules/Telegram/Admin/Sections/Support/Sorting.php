<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\ListScreen;

/**
 * The referral lists' order, as the panel's sortable headers give it: what they are ordered by (the list's `sort`
 * filter) and which way (its `dir` filter, which the panel's lists read as asc or desc). The way is said by what the
 * order is by — a count or a sum «بیشترین اول», a moment «تازه‌ترین اول» —, the panel's own (descending) first: none
 * sent, nothing said over the list.
 */
final class Sorting
{
    /** The query name of the direction (PageRequest::sort()). */
    public const DIR = 'dir';

    /** A count's or a sum's ways. */
    private const MOST = [ListScreen::ANY => 'بیشترین اول', 'asc' => 'کمترین اول'];

    /** A moment's ways. */
    private const NEWEST = [ListScreen::ANY => 'تازه‌ترین اول', 'asc' => 'قدیمی‌ترین اول'];

    /**
     * The ways list `$screen` may be ordered, as its filter's chips: in the words of what it is ordered by now — one of
     * `$sorts` (the first while none is picked), a moment if among `$moments`.
     *
     * @param array<string, string> $sorts
     * @param list<string> $moments
     * @return array<string, string>
     */
    public static function directions(?Admin $admin, string $screen, array $sorts, array $moments): array
    {
        $picked = ((array) ($admin?->state($screen)['f'] ?? []))['sort'] ?? null;
        $sort = is_string($picked) && isset($sorts[$picked]) ? $picked : (string) array_key_first($sorts);

        return in_array($sort, $moments, true) ? self::NEWEST : self::MOST;
    }
}
