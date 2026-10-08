<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Captcha\Verifier;
use App\Core\Http\Urls;
use App\Core\Mail\Mailer;
use App\Modules\Bots\Services\Bots;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\BotSettings;

/**
 * The shop as its website introduces it (`GET /` of the Store API), in the shop the request is worked in: its name —
 * the main shop's APP_NAME, an agent's its bot's title (Bots::name(): what the shop's emails are signed with too) —, its
 * bot and the link to it, whether it takes orders now (its bot's master switch: off, the website's checkout refuses
 * every order — CustomerCheckout::takingOrders()), how to reach support (the bot's «پشتیبانی», as a link), the ways a
 * customer signs in with their public ids — Telegram's Client ID, Google's, an email and a password while its sign-up is
 * on and the shop's email goes out —, the captcha its forms ask (what a page draws its widget with: the driver, its
 * public key, where it fetches its challenge — Core\Captcha\Verifier::widget()), and the referral program's terms.
 */
final class Storefront
{
    /** Where a widget that asks the shop for its challenge fetches one, under the website's base address. */
    public const CHALLENGE = '/captcha/challenge';

    public function __construct(
        private readonly Bots $bots,
        private readonly BotSettings $botSettings,
        private readonly ReferralSettings $referrals,
        private readonly Mailer $mailer,
        private readonly Verifier $captchas,
        private readonly Urls $urls,
    ) {}

    /**
     * @return array{shop: array{name: string, bot: array{username: string, url: string}|null, taking_orders: bool}, support: array{url: string}|null, sign_in: array{telegram: array{client_id: string, redirect: bool}|null, google: array{client_id: string}|null, email: bool}, captcha: array{driver: string, site_key: string|null, challenge_url: string|null}|null, referral: array{enabled: bool, rate: int, first_only: bool}}
     */
    public function present(Website $website): array
    {
        $bot = $website->shop();
        $username = $this->bots->username($bot);
        $support = $this->botSettings->supportUrl();
        $telegram = $website->telegramClientId();
        $google = $website->googleClientId();
        $captcha = $this->captchas->widget($website);

        return [
            'shop' => [
                'name' => $this->bots->name($bot),
                'bot' => $username === '' ? null : ['username' => $username, 'url' => 'https://t.me/' . $username],
                'taking_orders' => $this->botSettings->enabled(),
            ],
            'support' => $support === null ? null : ['url' => $support],
            'sign_in' => [
                'telegram' => $telegram === null ? null : ['client_id' => $telegram, 'redirect' => $website->hasTelegramSecret()],
                'google' => $google === null ? null : ['client_id' => $google],
                'email' => $website->email_signup && $this->mailer->ready(),
            ],
            'captcha' => $captcha === null ? null : [
                'driver' => $captcha['driver'],
                'site_key' => $captcha['site_key'],
                'challenge_url' => $captcha['challenges'] ? $this->urls->store($website->key) . self::CHALLENGE : null,
            ],
            'referral' => [
                'enabled' => $this->referrals->enabled(),
                'rate' => $this->referrals->rate(),
                'first_only' => $this->referrals->firstOnly(),
            ],
        ];
    }
}
