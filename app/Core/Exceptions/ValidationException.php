<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Input refused with a message under each field it concerns: the error handler answers it as a 422 with them under
 * `errors`, and a bot handler shows the one it needs.
 */
final class ValidationException extends DomainRuleException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'اطلاعات واردشده معتبر نیست.',
    ) {
        parent::__construct($message);
    }

    /** One field refused, its message the answer's too: what the screen shows over the form or in a toast. */
    public static function on(string $field, string $message): self
    {
        return new self([$field => [$message]], $message);
    }

    /** @param array<string, list<string>> $errors */
    public static function ifAny(array $errors): void
    {
        if ($errors !== []) {
            throw new self($errors);
        }
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
