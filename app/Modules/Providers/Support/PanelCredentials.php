<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Models\Server;

/**
 * The way into a panel, as every connector takes it: an API token, or an admin's username and password, kept in
 * `servers.api_token`, `servers.username` and `servers.password` (secrets encrypted). A server keeps one way at a time;
 * which credentials it has is its way in (of()). Every connector's form uses the same fields (fields()); a connector
 * may add fields to the password way (WITH_PASSWORD: 3x-ui's two-factor secret). A secret left blank keeps the stored
 * one only while the panel's address stays on its host (PanelConnection::bound()).
 */
final class PanelCredentials
{
    /** The field the way in is picked by; no column of its own — it is which credentials a server keeps (of()). */
    public const MODE = 'auth_mode';

    /** The `when` of every field of the password way in (FieldSpec::$when). */
    public const WITH_PASSWORD = [self::MODE => [AuthMode::Password->value]];

    /** The longest username kept (servers.username). */
    private const USERNAME_MAX = 128;

    public function __construct(
        public readonly AuthMode $mode,
        public readonly ?string $token = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
    ) {}

    /** The credentials a stored server signs in with. */
    public static function of(Server $server): self
    {
        return ($server->api_token ?? '') !== ''
            ? new self(AuthMode::Token, token: $server->api_token)
            : new self(AuthMode::Password, username: $server->username, password: $server->password);
    }

    /**
     * The form's fields of the way in: which way; the API token, worded by the connector (`$token`: «توکن API»,
     * «کلید API»; `$tokenHint`: where on the panel it is made) and held to its shape (`$tokenPattern`, refused with
     * `$tokenMismatch`); or an admin's username and password (`$loginHint`: whose).
     *
     * @return list<Field<mixed>>
     */
    public static function fields(string $token, string $tokenHint, string $tokenPattern, string $tokenMismatch, string $loginHint): array
    {
        return [
            new Choice(
                self::MODE,
                self::MODE,
                AuthMode::Token->value,
                array_column(AuthMode::cases(), 'value'),
                refusal: 'روش احراز هویت نامعتبر است.',
                spec: new FieldSpec('احراز هویت', options: [AuthMode::Token->value => $token, AuthMode::Password->value => 'نام کاربری و رمز']),
            ),
            new Secret(
                'api_token',
                'api_token',
                '',
                pattern: $tokenPattern,
                mismatch: $tokenMismatch,
                missing: "{$token} را وارد کنید.",
                boundTo: PanelConnection::bound(),
                moved: "آدرس پنل عوض شده است؛ {$token} را دوباره وارد کنید.",
                spec: new FieldSpec($token, hint: $tokenHint, when: [self::MODE => [AuthMode::Token->value]]),
            ),
            new Text('username', 'username', '', label: 'نام کاربری', max: self::USERNAME_MAX, required: true, spec: new FieldSpec('نام کاربری', hint: $loginHint, when: self::WITH_PASSWORD, ltr: true)),
            // A password is no token: its hint gives none of it away.
            new Secret(
                'password',
                'password',
                '',
                pattern: '/^[^\x00-\x1f\x7f]{1,255}$/u',
                mismatch: 'رمز عبور حداکثر 255 کاراکتر است، بدون کاراکترهای کنترلی.',
                hint: static fn(string $password): string => '••••••••',
                missing: 'رمز عبور را وارد کنید.',
                boundTo: PanelConnection::bound(),
                moved: 'آدرس پنل عوض شده است؛ رمز عبور را دوباره وارد کنید.',
                spec: new FieldSpec('رمز عبور', when: self::WITH_PASSWORD),
            ),
        ];
    }

    /** An API token to sign in with. */
    public function usesToken(): bool
    {
        return $this->mode === AuthMode::Token && ($this->token ?? '') !== '';
    }

    /** A username and password to sign in with. */
    public function canLogin(): bool
    {
        return ($this->username ?? '') !== '' && ($this->password ?? '') !== '';
    }
}
