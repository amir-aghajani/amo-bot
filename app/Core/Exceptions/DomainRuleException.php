<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A refusal the user can act on, its message already written for them (in Persian). ErrorHandler answers it as it is —
 * status(), the message, errors() under the fields concerned — so controllers let it through; the bot catches the
 * subclasses it phrases differently. A subclass overrides status() (422 by default) and field() when one field is at fault.
 */
abstract class DomainRuleException extends \RuntimeException
{
    /** 422 refused in this state, 409 another row still holds on to it, 502 a panel or service the shop relies on failed. */
    public function status(): int
    {
        return 422;
    }

    /**
     * The message under each request field it concerns; empty when it is about the request as a whole.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $field = $this->field();

        return $field === null ? [] : [$field => [$this->getMessage()]];
    }

    /** The one request field it concerns, if any: the message is repeated under errors[field]. */
    protected function field(): ?string
    {
        return null;
    }
}
