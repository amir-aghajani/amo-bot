<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

/**
 * A section of the customer card that asks for typed input (CustomerCards::ask()): it gets the admin's reply to its
 * question, already checked, and answers with CustomerCards::done() — the card rewritten, the question gone — or
 * CustomerCards::retry() — a word under the reply, the question kept for another try.
 */
interface AnswersPrompts
{
    public function answered(CardReply $reply): void;
}
