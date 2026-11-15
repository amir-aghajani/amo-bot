<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * What the owner's screens say alike: who did something (the panel's Reviewer), a customer, a driver's note, an action
 * with an optional note asked first.
 */
final class Words
{
    /** The hint of a note asked after «✍️ با توضیح»: who reads it. */
    public const NOTE_HINT = 'با خبرش برای او فرستاده می‌شود و آن را به عنوان توضیح پشتیبانی می‌بیند.';

    /**
     * An action with an optional note asked first (reject, stop selling, end an agency…): `$question` (plain text), what
     * it does (`$detail`, HTML), then «✍️ با توضیح» (`$with`: asks the note, then does it), «⏭ بدون توضیح» (`$without`:
     * does it) and «✖️ انصراف» (`$no`). A typed note alone is never the yes: it is asked only after «✍️ با توضیح».
     */
    public static function withNote(string $question, string $detail, Button $with, Button $without, Button $no): View
    {
        return View::make('❓ ' . $question)->line($detail)->row($with, $without)->row($no);
    }

    /**
     * Who decided or started something: the bot admin's name with their handle, or the handle alone (the panel's login,
     * an agent's bot) — HTML; null for nobody.
     *
     * @param array<string, mixed> $row With `reviewer` and `reviewer_name`
     */
    public static function reviewer(array $row): ?string
    {
        $handle = is_string($row['reviewer'] ?? null) && $row['reviewer'] !== '' ? $row['reviewer'] : null;
        if ($handle === null) {
            return null;
        }

        return is_string($row['reviewer_name'] ?? null) && $row['reviewer_name'] !== '' ? Fmt::e($row['reviewer_name']) . ' (' . Fmt::ltr($handle) . ')' : Fmt::ltr($handle);
    }

    /** A customer (the API's UserRef) in one line of plain text: their name, else their handle, email or number. */
    public static function user(mixed $user): string
    {
        $user = is_array($user) ? $user : [];

        return match (true) {
            is_string($user['name'] ?? null) && $user['name'] !== '' => $user['name'],
            is_string($user['username'] ?? null) && $user['username'] !== '' => '@' . $user['username'],
            is_string($user['email'] ?? null) && $user['email'] !== '' => $user['email'],
            default => '#' . (int) ($user['id'] ?? 0),
        };
    }

    /** A customer as a line says them (HTML): their name, handle and Telegram id. */
    public static function who(mixed $user): string
    {
        $user = is_array($user) ? $user : [];
        $handle = is_string($user['username'] ?? null) && $user['username'] !== '' ? ' · ' . Fmt::ltr('@' . $user['username']) : '';
        $telegram = is_int($user['telegram_id'] ?? null) ? ' · ' . Fmt::code((string) $user['telegram_id']) : '';

        return Fmt::e(is_string($user['name'] ?? null) && $user['name'] !== '' ? $user['name'] : self::user($user)) . $handle . $telegram;
    }

    /**
     * A driver's note as HTML: an address in it set apart left to right and copyable (the panel's GatewayNotes).
     */
    public static function note(string $text): string
    {
        $parts = preg_split('#(https?://\S+)#u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        return implode('', array_map(static fn(int $index, string $part): string => $index % 2 === 1 ? Fmt::code($part) : Fmt::e($part), array_keys($parts), $parts));
    }
}
