<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Captcha\Verifier;
use App\Core\Http\Urls;
use App\Core\Mail\Mailer;
use App\Modules\Bots\Services\Bots;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Rewards\Services\CashbackSettings;
use App\Modules\Rewards\Services\GiftCodeSettings;
use App\Modules\Rewards\Services\LotterySettings;
use App\Modules\Rewards\Services\WheelSettings;
use App\Modules\Store\Contracts\Door;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Services\ClientNameSettings;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Subscriptions\Services\TestAccountSettings;
use App\Modules\Telegram\BotSettings;
use App\Modules\Users\Services\WalletTransferSettings;
use App\Support\LocalTime;

/**
 * The Store API's `GET /`: what a website or the mini app needs first about its shop — its name (Bots::name()) and bot,
 * whether it takes orders now (the bot's master switch), services by their link (ServiceClaims::offered()), a service
 * handed to another customer (ServiceTransfers::offered()) and services its customers build (CustomServices::offered()),
 * the ways a buyer may name their new service (ClientNameSettings::modes()), which of the shop's programs run now
 * (`programs`), support's link, the ways to sign in with their public ids, the captcha widget to draw
 * (Core\Captcha\Verifier::widget()), the referral terms — and, through the mini app, how it looks and its announcement
 * (`app`; null through a website).
 */
final class Storefront
{
    /** Where a widget that asks the shop for a challenge fetches it, under the website's base address. */
    public const CHALLENGE = '/captcha/challenge';

    /** Where the mini app's logo is, under its base address. */
    public const LOGO = '/app/logo';

    public function __construct(
        private readonly Bots $bots,
        private readonly BotSettings $botSettings,
        private readonly ReferralSettings $referrals,
        private readonly ServiceClaims $claims,
        private readonly ServiceTransfers $transfers,
        private readonly CustomServices $custom,
        private readonly ClientNameSettings $names,
        private readonly Mailer $mailer,
        private readonly Verifier $captchas,
        private readonly Urls $urls,
        private readonly TestAccountSettings $tests,
        private readonly WheelSettings $wheel,
        private readonly LotterySettings $lottery,
        private readonly GiftCodeSettings $giftCodes,
        private readonly CashbackSettings $cashback,
        private readonly WalletTransferSettings $walletTransfers,
        private readonly Loyalty $loyalty,
    ) {}

    /**
     * @return array{shop: array{name: string, bot: array{username: string, url: string}|null, taking_orders: bool, phone_required: bool, service_claims: bool, service_transfers: bool, custom_services: bool, name_modes: list<string>, programs: array{test_accounts: bool, wheel: bool, lottery: bool, gift_codes: bool, loyalty: bool, cashback: bool, wallet_transfers: bool, reviews: bool}}, support: array{url: string}|null, sign_in: array{telegram: array{client_id: string, redirect: bool}|null, google: array{client_id: string}|null, email: bool, phone: bool, mini_app: bool}, captcha: array{driver: string, site_key: string|null, challenge_url: string|null}|null, referral: array{enabled: bool, rate: int, first_only: bool}, app: array{title: string, accent_color: string|null, logo_url: string|null, announcement: array{text: string, url: string|null}|null, fullscreen: bool, time_zone: string}|null}
     */
    public function present(Door $door): array
    {
        $bot = $door->shop();
        $username = $this->bots->username($bot);
        $support = $this->botSettings->supportUrl();
        $telegram = $door->telegramClientId();
        $google = $door->googleClientId();
        $captcha = $this->captchas->widget($door);

        return [
            'shop' => [
                'name' => $this->bots->name($bot),
                'bot' => $username === '' ? null : ['username' => $username, 'url' => 'https://t.me/' . $username],
                // Off while the bot is off — and while an agent's selling is suspended (Bot::sells()).
                'taking_orders' => $this->botSettings->enabled() && $bot->sells(),
                // The bot asks every customer to share their own number first («تایید شماره»): the mini app asks it too.
                'phone_required' => $this->botSettings->phoneRequired(),
                'service_claims' => $this->claims->offered(),
                'service_transfers' => $this->transfers->offered(),
                'custom_services' => $this->custom->offered(),
                'name_modes' => array_map(static fn(ClientNameMode $mode): string => $mode->value, $this->names->modes()),
                'programs' => [
                    'test_accounts' => $this->tests->enabled(),
                    'wheel' => $this->wheel->enabled(),
                    'lottery' => $this->lottery->enabled(),
                    'gift_codes' => $this->giftCodes->enabled(),
                    'loyalty' => $this->loyalty->tiers() !== [],
                    'cashback' => $this->cashback->enabled(),
                    'wallet_transfers' => $this->walletTransfers->enabled(),
                    // This door's own switch: the website's reviews, or the mini app's.
                    'reviews' => $door->showsReviews(),
                ],
            ],
            'support' => $support === null ? null : ['url' => $support],
            'sign_in' => [
                'telegram' => $telegram === null ? null : ['client_id' => $telegram, 'redirect' => $door->hasTelegramSecret()],
                'google' => $google === null ? null : ['client_id' => $google],
                'email' => $door->allowsEmailSignUp() && $this->mailer->ready(),
                // A code by SMS: the site's switch, and SMS going out for it (Website::allowsPhoneSignIn()).
                'phone' => $door->allowsPhoneSignIn(),
                // The mini app signs in by Telegram's launch data alone (POST /auth/mini-app).
                'mini_app' => $door instanceof MiniApp,
            ],
            'captcha' => $captcha === null ? null : [
                'driver' => $captcha['driver'],
                'site_key' => $captcha['site_key'],
                'challenge_url' => $captcha['challenges'] ? $this->urls->store($door->storeKey()) . self::CHALLENGE : null,
            ],
            'referral' => [
                'enabled' => $this->referrals->enabled(),
                'rate' => $this->referrals->rate(),
                'first_only' => $this->referrals->firstOnly(),
            ],
            'app' => $door instanceof MiniApp ? $this->app($door) : null,
        ];
    }

    /**
     * How the mini app looks — its title (else the shop's name), its colour, its logo's address —, its announcement,
     * whether it opens full screen, and the shop's time zone, which it shows every date in.
     *
     * @return array{title: string, accent_color: string|null, logo_url: string|null, announcement: array{text: string, url: string|null}|null, fullscreen: bool, time_zone: string}
     */
    private function app(MiniApp $app): array
    {
        return [
            'title' => $app->title ?? $this->bots->name($app->shop()),
            'accent_color' => $app->accent_color,
            // Versioned by the row's last change: a logo replaced under the same name is never an old copy a phone kept.
            'logo_url' => $app->logo_path === null ? null : $this->urls->store($app->key) . self::LOGO . '?v=' . ($app->updated_at?->getTimestamp() ?? 0),
            'announcement' => $app->announcement === null ? null : ['text' => $app->announcement, 'url' => $app->announcement_url],
            'fullscreen' => $app->fullscreen,
            'time_zone' => LocalTime::zone()->getName(),
        ];
    }
}
