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
 * The Store API's `GET /`: what a website needs first about its shop — its name (Bots::name()) and bot, whether it takes
 * orders now (the bot's master switch), support's link, the ways to sign in with their public ids, the captcha widget to
 * draw (Core\Captcha\Verifier::widget()) and the referral terms.
 */
final class Storefront
{
    /** Where a widget that asks the shop for a challenge fetches it, under the website's base address. */
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
