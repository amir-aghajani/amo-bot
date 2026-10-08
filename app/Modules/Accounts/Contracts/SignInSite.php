<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Contracts;

use App\Core\Captcha\CaptchaSite;
use App\Modules\Bots\Models\Bot;

/**
 * The website a customer signs in on, as signing in needs it: the shop it is, the ways in its owner set up — Telegram
 * (its Client ID, and the secret the redirect flow exchanges a code with), Google, an email and a password —, the
 * captcha it asks (CaptchaSite: the driver, its settings, the hosts its pages are on), and the origins its pages are on.
 * The Accounts module owns it and the shop's website implements it (the Store module's Website), so the dependency runs
 * one way: the website's API calls the account's services, and those name no website.
 */
interface SignInSite extends CaptchaSite
{
    /** The shop the site is: its name signs the emails a sign-in sends and names the two-factor app's entry. */
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
