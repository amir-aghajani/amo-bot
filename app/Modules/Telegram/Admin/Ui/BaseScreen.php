<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\AdminSeat;

/**
 * A screen's defaults: every seat may open it, an action it does not know (a button of an older version of it) shows it
 * again, and a question it never asked is answered by showing it.
 */
abstract class BaseScreen implements Screen
{
    /** The furthest page a button may name (page()). */
    protected const PAGE_MOST = 100_000;

    public function allows(AdminSeat $seat): bool
    {
        return true;
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return $this->show($admin, $args);
    }

    /**
     * The argument at `$index` as a row's id; 0 for none.
     *
     * @param list<string> $args
     */
    protected static function id(array $args, int $index = 0): int
    {
        return isset($args[$index]) && ctype_digit($args[$index]) ? (int) $args[$index] : 0;
    }

    /**
     * The argument at `$index` as a page number, 1 for none — held under PAGE_MOST: a list counts its rows from it, and
     * a number past any list's is its last page all the same.
     *
     * @param list<string> $args
     */
    protected static function page(array $args, int $index = 0): int
    {
        return min(max(1, self::id($args, $index)), self::PAGE_MOST);
    }
}
