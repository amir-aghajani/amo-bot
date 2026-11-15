<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Door;

/**
 * The panels' API said no to a request from the bot: its status, its Persian message — what the panel shows — and, for
 * a form the API refused (422), each field's messages. The bot shows them as the panel does.
 */
final class ApiRefusal extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors By field
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    /** What was typed for `$field` refused by the bot itself before any request, as the panel refuses a field (422). */
    public static function typed(string $field, string $message): self
    {
        return new self(422, $message, [$field => [$message]]);
    }

    /**
     * Every message, the general one and each field's, as lines a chat shows; a field's under its own label when one is
     * given.
     *
     * @param array<string, string> $labels By field
     */
    public function lines(array $labels = []): string
    {
        $lines = [];
        foreach ($this->errors as $field => $messages) {
            foreach ($messages as $message) {
                $lines[] = isset($labels[$field]) ? "• {$labels[$field]}: {$message}" : "• {$message}";
            }
        }
        if ($lines === []) {
            return $this->getMessage();
        }

        return count($lines) === 1 ? ltrim($lines[0], '• ') : implode("\n", $lines);
    }

    /** The first message for `$field`, or null. */
    public function field(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
