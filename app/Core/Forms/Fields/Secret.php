<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Http\Origin;
use App\Support\Input;

/**
 * A secret a screen never gets back (a token, a password): presented as whether one is kept, with a hint. Left blank
 * or unsent it keeps the stored one; `clear_<name>` empties it; `missing` makes one required.
 *
 * `boundTo` names the fields it belongs with (a server's address, the account on it). While one of them changes, a
 * blank secret no longer keeps the stored one — refused in `moved`'s words — so a kept secret never goes to a server it
 * was not given for.
 *
 * @extends Field<string>
 */
final class Secret extends Field
{
    /** The hint of a kept secret too short to show any of: that one is kept. */
    public const KEPT = '••••••••';

    /** How many of a kept secret's last characters its default hint shows, and how long it must be to show them. */
    private const HINTED = 4;
    private const HINTED_FROM = 12;

    /**
     * @param (\Closure(string): string)|null $hint How a kept one is recognised; by default its last characters (lastCharacters())
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
        /** The refusal when none would be kept; null: it may be left without one */
        private readonly ?string $missing = null,
        public readonly array $boundTo = [],
        /** The refusal of a blank one while a field it belongs with moved: type it again, or clear it */
        public readonly string $moved = '',
        ?FieldSpec $spec = null,
    ) {
        if ($boundTo !== [] && $moved === '') {
            throw new \LogicException("The secret \"{$name}\" is bound to fields and has no words for when one of them moves.");
        }
        parent::__construct($name, $key, $default, $spec);
    }

    /**
     * Identifies a bound address by its origin (scheme, host and port): another path on it is the same server.
     *
     * @return \Closure(mixed): ?string
     */
    public static function byOrigin(): \Closure
    {
        return static fn(mixed $url): ?string => is_string($url) ? Origin::of($url) : null;
    }

    /**
     * The hint of a secret none of whose characters are shown, however long (a password, a two-factor secret): only that
     * one is kept.
     *
     * @return \Closure(string): string
     */
    public static function hidden(): \Closure
    {
        return static fn(string $secret): string => self::KEPT;
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

        return ['set' => true, 'hint' => $this->hint !== null ? ($this->hint)($value) : self::lastCharacters($value)];
    }

    /**
     * A kept secret's default hint: its last HINTED characters behind dots — of one at least HINTED_FROM long, which they
     * give little of away —; a shorter one's dots alone, saying only that one is kept.
     */
    private static function lastCharacters(string $secret): string
    {
        return mb_strlen($secret) >= self::HINTED_FROM ? '••••••' . mb_substr($secret, -self::HINTED) : self::KEPT;
    }

    /** `moved`'s words when the input keeps the stored secret (blank, not cleared) while a field it is bound to changed. */
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
