<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

/**
 * An OpenID provider could not be asked what a sign-in needs — its signing keys, a code's tokens: out of reach, or an
 * answer that was not its API's. Nothing about the sign-in itself; the provider's sign-in words it for the customer (a
 * 502: Telegram\Api\TelegramUnreachableException for Telegram's).
 */
final class ProviderUnreachableException extends \RuntimeException {}
