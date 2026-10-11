<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

/**
 * An OpenID provider could not be asked (its keys, a code's tokens): out of reach, or an answer not its API's. Says
 * nothing about the sign-in itself; ProviderSignIn::prove() turns it into the provider's 502.
 */
final class ProviderUnreachableException extends \RuntimeException {}
