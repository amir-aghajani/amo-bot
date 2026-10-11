<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Contracts;

use App\Core\Captcha\CaptchaSite;
use App\Modules\Bots\Models\Bot;

/**
 * The website a customer signs in on, as the sign-in services need it: its shop, the ways in its owner set up, its
 * captcha and its origins. Accounts owns this interface and Store's Website implements it, so the dependency runs one
 * way: the website's API calls the account services, which name no website (LayersTest holds it).
 */
interface SignInSite extends CaptchaSite
{
    /** The site's shop: its name signs the sign-in emails and names the two-factor app's entry. */
    public function shop(): Bot;

    /** The Client ID customers sign in with Telegram under, while that sign-in is on and one is kept; null otherwise. */
    public function telegramClientId(): ?string;

    /** Whether the redirect flow is set up: a client secret is kept to exchange a code with. */
    public function hasTelegramSecret(): bool;

    /** That client secret; null while none is kept. */
    public function telegramClientSecret(): ?string;

    /** The OAuth client id customers sign in with Google under; null while there is none (Google sign-in off). */
    public function googleClientId(): ?string;

    /** Whether customers may sign up with an email and a password (signing in and a reset do not ask it). */
    public function allowsEmailSignUp(): bool;

    /** Whether a page on `$origin` (as a browser sends it) is the site's: its own origin, or one it lists. */
    public function allowsOrigin(string $origin): bool;
}
