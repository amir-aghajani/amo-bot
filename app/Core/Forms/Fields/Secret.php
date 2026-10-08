<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Http\Origin;
use App\Support\Input;

/**
 * A secret a screen never gets back (a token, a password): shown as whether one is kept and a hint that does not give
 * it away; left blank or not sent it keeps what is kept, `clear_<name>` empties it (Input::secret()), and what is kept
 * from then on must have its `pattern` — and be there at all, when a form left without one is refused (`missing`).
 *
 * A kept secret goes only where it was given (`boundTo`): the fields it belongs with — a server's address, the account
 * on it —, each read by what identifies it (a URL by its origin) or as it is. While one of them moves, a secret left
 * blank no longer keeps the one stored: the form refuses it in `moved`'s words, to be typed again or cleared (Form).
 *
 * @extends Field<string>
 */
final class Secret extends Field
{
    /**
     * @param (\Closure(string): string)|null $hint How a kept one is recognised; by default its last characters
     * @param array<string, (\Closure(mixed): mixed)|null> $boundTo The fields of the form it belongs with, by name — each
     *                                                              with what identifies it, or null for its value as it is
     */
    public function __construct(
        string $name,
        string $key,
        string $default,
        private readonly string $pattern,
        private readonly string $mismatch,
        private readonly ?\Closure $hint = null,
        /** What a form that leaves none kept is told; null: it may be left without one */
        private readonly ?string $missing = null,
        public readonly array $boundTo = [],
        /** What a blank one is told while a field it belongs with moved: to type it again, or clear it */
        public readonly string $moved = '',
        ?FieldSpec $spec = null,
    ) {
        if ($boundTo !== [] && $moved === '') {
            throw new \LogicException("The secret \"{$name}\" is bound to fields and has no words for when one of them moves.");
        }
        parent::__construct($name, $key, $default, $spec);
    }

    /**
     * What identifies an address a secret is bound to (`boundTo`): its origin — scheme, host and port, Core\Http\Origin's
     * one form; another path is the same server. Null for what is no http(s) address.
     *
     * @return \Closure(mixed): ?string
     */
    public static function byOrigin(): \Closure
    {
        return static fn(mixed $url): ?string => is_string($url) ? Origin::of($url) : null;
    }

    public function type(): FieldType
    {
        return FieldType::Secret;
    }

    public function required(): bool
    {
        return $this->missing !== null;
    }

    public function read(array $input, mixed $kept): string
    {
        $value = Input::secret($input, $this->name, is_string($kept) ? $kept : '');
        if ($value === '' && $this->missing !== null) {
            throw new FieldRefused($this->missing);
        }
        if ($value !== '' && preg_match($this->pattern, $value) !== 1) {
            throw new FieldRefused($this->mismatch);
        }

        return $value;
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) ? $kept : $this->default;
    }

    /** @return array{set: bool, hint: string} */
    public function present(mixed $value): array
    {
        if ($value === '') {
            return ['set' => false, 'hint' => ''];
        }

        return ['set' => true, 'hint' => $this->hint !== null ? ($this->hint)($value) : '••••••' . substr($value, -4)];
    }

    /**
     * `moved`'s words while the input keeps the secret stored — leaves it blank or out, and does not clear it — and a
     * field it belongs with moved: it would go where it was never given.
     */
    public function leftBehind(array $input, array $values, array $before): ?string
    {
        if ($this->boundTo === [] || ($values[$this->name] ?? '') === '' || Input::text($input, $this->name) !== '' || Input::truthy($input['clear_' . $this->name] ?? false)) {
            return null;
        }
        foreach ($this->boundTo as $name => $identify) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $now = $values[$name];
            $was = $before[$name] ?? null;
            if ($identify !== null ? $identify($now) !== $identify($was) : $now !== $was) {
                return $this->moved;
            }
        }

        return null;
    }

    public function describe(): array
    {
        $described = parent::describe();
        $described['secret'] = true;
        $described['bound_to'] = array_keys($this->boundTo);
        $described['moved'] = $this->boundTo === [] ? null : $this->moved;
        $described['default'] = null;

        return $described;
    }
}
