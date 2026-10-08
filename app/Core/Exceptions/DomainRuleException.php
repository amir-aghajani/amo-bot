<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * The shop said no, because…: a refusal the person who asked can act on, its message already in their words (Persian).
 * The HTTP side answers it as it is — status(), the message, and errors() under the request fields it concerns — so a
 * controller lets it through instead of catching it (Core\Http\ErrorHandler); the bot catches the subclasses it words
 * its own way. A subclass says what it is with status() (422 by default: refused in this state) and field() when it is
 * about one field of the request; ValidationException carries several fields.
 */
abstract class DomainRuleException extends \RuntimeException
{
    /** 422 refused in this state, 409 another row still holds on to it, 502 a panel the shop relies on failed. */
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

    /** The one request field it concerns, if any: the answer repeats the message under errors[field]. */
    protected function field(): ?string
    {
        return null;
    }
}
