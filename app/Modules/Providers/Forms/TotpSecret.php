<?php

declare(strict_types=1);

namespace App\Modules\Providers\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;

/**
 * The secret of a panel's two-factor login, whose codes the shop signs in with (Core\Security\Totp): base32, typed the
 * way an authenticator app shows it — in groups, in either case — and kept in one form, without spaces or dashes, upper
 * case. Everything else is the Secret's: never shown, kept unless typed again or cleared, and only while what it is
 * bound to stays.
 *
 * @extends Field<string>
 */
final class TotpSecret extends Field
{
    private readonly Secret $secret;

    /** @param array<string, (\Closure(mixed): mixed)|null> $boundTo As the Secret's */
    public function __construct(string $name, string $key, array $boundTo, string $moved, FieldSpec $spec)
    {
        $this->secret = new Secret(
            $name,
            $key,
            '',
            pattern: '/^[A-Z2-7]+=*$/',
            mismatch: 'کلید TOTP باید به فرمت Base32 باشد (حروف A تا Z و ارقام 2 تا 7).',
            // A secret's characters are no token's: the hint gives none of them away.
            hint: static fn(string $secret): string => '••••••••',
            boundTo: $boundTo,
            moved: $moved,
            spec: $spec,
        );
        parent::__construct($name, $key, '', $spec);
    }

    public function type(): FieldType
    {
        return $this->secret->type();
    }

    public function required(): bool
    {
        return $this->secret->required();
    }

    public function read(array $input, mixed $kept): string
    {
        $typed = $input[$this->name] ?? null;
        if (is_string($typed)) {
            $input[$this->name] = strtoupper(str_replace([' ', '-'], '', $typed));
        }

        return $this->secret->read($input, $kept);
    }

    public function cast(mixed $kept): string
    {
        return $this->secret->cast($kept);
    }

    /** @return array{set: bool, hint: string} */
    public function present(mixed $value): array
    {
        return $this->secret->present($value);
    }

    public function leftBehind(array $input, array $values, array $before): ?string
    {
        return $this->secret->leftBehind($input, $values, $before);
    }

    public function describe(): array
    {
        return $this->secret->describe();
    }
}
