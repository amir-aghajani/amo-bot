<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/**
 * The management screens' button data: `a:<screen>:<action>[:<arg>…]` — a screen's id, what to do there, and its
 * arguments (ids, page numbers, short keys). Telegram carries 64 bytes of it, so nothing longer is made: text (a search,
 * a draft) is kept in the screen's state (Admin::state()), never in a button.
 */
final class Nav
{
    /** What every management button's data starts with (routes/bot.php). */
    public const PREFIX = 'a:';

    /** The action a screen is opened with. */
    public const SHOW = 'show';

    /** The most Telegram carries in a button's data. */
    private const MOST = 64;

    /** What an id, an action or an argument may be: no colon, nothing Telegram would mangle. */
    private const PART = '/^[A-Za-z0-9_.\-]+$/';

    /**
     * A button's data.
     *
     * @throws \LogicException for a part that is not one, or data past 64 bytes: the screen's mistake
     */
    public static function to(string $screen, string $action = self::SHOW, int|string ...$args): string
    {
        $parts = [$screen, $action, ...array_map(strval(...), $args)];
        foreach ($parts as $part) {
            if (preg_match(self::PART, $part) !== 1) {
                throw new \LogicException("A management button's part \"{$part}\" carries what it may not.");
            }
        }
        $data = self::PREFIX . implode(':', $parts);
        if (strlen($data) > self::MOST) {
            throw new \LogicException("A management button's data is past 64 bytes: {$data}");
        }

        return $data;
    }

    /**
     * A button's data read back: its screen, action and arguments; null for data that is none — a part to() never
     * makes (a query, a path, a word) too, so what no button carries reaches no screen.
     *
     * @return array{screen: string, action: string, args: list<string>}|null
     */
    public static function parse(?string $data): ?array
    {
        if ($data === null || !str_starts_with($data, self::PREFIX) || strlen($data) > self::MOST) {
            return null;
        }
        $parts = explode(':', substr($data, strlen(self::PREFIX)));
        if (count($parts) < 2 || array_filter($parts, static fn(string $part): bool => preg_match(self::PART, $part) !== 1) !== []) {
            return null;
        }

        return ['screen' => $parts[0], 'action' => $parts[1], 'args' => array_slice($parts, 2)];
    }
}
