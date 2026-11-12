<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Modules\Users\Models\User;

/**
 * A bot admin's reply to a customer card's question (CustomerCards::ask()), checked (CustomerCards::reply()): written
 * by one of the bot's admins, about a customer of the shop still there. `kind` and `args` are what the question
 * recorded; `text` is what the admin wrote, trimmed. `cardId` is the card the question was asked under, which the
 * answer rewrites; `promptId` the question itself. `questionId` is the question's record, taken by this reply with
 * `claim` (CustomerCards::retry() gives it back by it).
 */
final class CardReply
{
    /**
     * @param array<string, mixed> $message The admin's message
     * @param list<string> $args
     */
    public function __construct(
        public readonly int $chatId,
        public readonly array $message,
        public readonly int $promptId,
        public readonly int $cardId,
        public readonly User $admin,
        public readonly User $user,
        public readonly string $kind,
        public readonly array $args,
        public readonly string $text,
        public readonly int $questionId,
        public readonly string $claim,
    ) {}

    /** The n-th recorded argument, as a whole number; null when missing or not one. */
    public function int(int $index): ?int
    {
        $arg = $this->args[$index] ?? '';

        return ctype_digit($arg) && strlen($arg) <= 18 ? (int) $arg : null;
    }
}
