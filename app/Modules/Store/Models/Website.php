<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Core\Database\Casts\Encrypted;
use App\Core\Database\Casts\EncryptedArray;
use App\Core\Http\Origin;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Store\Enums\StaffGrant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A shop's website: the switch the Store API answers it by, the public store key its address carries, where the site
 * is and which other origins a browser may call the API from, how its customers sign in — with Telegram, with an email
 * and a password, with Google — and the captcha its forms ask (a driver of Core\Captcha, its settings): what the
 * account's services ask of the site they sign a customer in on (Accounts\Contracts\SignInSite, a Core\Captcha\
 * CaptchaSite too). Whether it shows its customers' reviews and takes new ones (`reviews_enabled`). And whether the
 * shop's admins work the shop from it — the strong sign-in it asks of them, what it grants them (Http\StaffMiddleware).
 * One per bot, made by Services\Websites the first time its panel asks.
 *
 * @property int $id
 * @property string $key The store key: 24 lower-case hex characters — it names the shop, it is no secret
 * @property bool $enabled
 * @property string|null $url The site's address, without a trailing slash; its origin may call the API
 * @property list<string>|null $origins The other origins that may (`scheme://host[:port]`)
 * @property bool $telegram_login Whether customers sign in with their Telegram account
 * @property string|null $telegram_client_id The site's Client ID as @BotFather shows it (digits)
 * @property string|null $telegram_client_secret Its secret, encrypted at rest — only the redirect flow needs it
 * @property bool $email_signup Whether customers sign up with their email and a password (the installation's mail sends their codes)
 * @property string|null $google_client_id The site's OAuth client id in Google Cloud: Google sign-in is on while there is one
 * @property string|null $captcha_driver The captcha its forms ask: a driver's key (Core\Captcha\Verifier); null: none
 * @property array<string, mixed>|null $captcha_config What that driver's form keeps, by key — encrypted at rest (Turnstile's secret among it)
 * @property bool $reviews_enabled Whether it shows the reviews support approved and takes new ones (GET and POST /reviews)
 * @property bool $staff_enabled Whether the shop's admins work it from the website (its admin API, Http\StaffMiddleware)
 * @property bool $staff_strong_sign_in Whether they must have signed in strongly: Telegram, Google, or a password with its second step
 * @property list<string>|null $staff_grants What they may do beyond the shop's daily work (Enums\StaffGrant values)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Website extends Model implements SignInSite
{
    use BelongsToBot;

    protected $table = 'websites';

    protected $fillable = [
        'key',
        'enabled',
        'url',
        'origins',
        'telegram_login',
        'telegram_client_id',
        'telegram_client_secret',
        'email_signup',
        'google_client_id',
        'captcha_driver',
        'captcha_config',
        'reviews_enabled',
        'staff_enabled',
        'staff_strong_sign_in',
        'staff_grants',
    ];

    protected $hidden = ['telegram_client_secret', 'captcha_config'];

    /**
     * A website starts switched off, and so do its ways to sign in, its reviews — and its door for the shop's admins:
     * shut, a strong sign-in asked of them once it opens, nothing granted beyond the shop's daily work.
     */
    protected $attributes = [
        'enabled' => false,
        'telegram_login' => false,
        'email_signup' => false,
        'reviews_enabled' => false,
        'staff_enabled' => false,
        'staff_strong_sign_in' => true,
    ];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
        'origins' => 'array',
        'telegram_login' => 'boolean',
        'telegram_client_secret' => Encrypted::class,
        'email_signup' => 'boolean',
        'captcha_config' => EncryptedArray::class,
        'reviews_enabled' => 'boolean',
        'staff_enabled' => 'boolean',
        'staff_strong_sign_in' => 'boolean',
        'staff_grants' => 'array',
    ];

    /** Whether a page on `$origin` (as the browser sends it) may call the API: the site's own origin, or one it lists. */
    public function allowsOrigin(string $origin): bool
    {
        $origin = Origin::normalize($origin);
        if ($origin === null) {
            return false;
        }

        return $origin === Origin::of((string) $this->url) || in_array($origin, $this->origins ?? [], true);
    }

    /** The Client ID customers sign in with Telegram under — while that sign-in is on and one is kept; null otherwise. */
    public function telegramClientId(): ?string
    {
        return $this->telegram_login && ($this->telegram_client_id ?? '') !== '' ? $this->telegram_client_id : null;
    }

    /** Whether the redirect flow can exchange a code: a client secret is kept. */
    public function hasTelegramSecret(): bool
    {
        return $this->telegramClientSecret() !== null;
    }

    /** The client secret the redirect flow exchanges a code with; null while none is kept. */
    public function telegramClientSecret(): ?string
    {
        return ($this->telegram_client_secret ?? '') !== '' ? $this->telegram_client_secret : null;
    }

    /** The OAuth client id customers sign in with Google under; null while there is none (Google sign-in off). */
    public function googleClientId(): ?string
    {
        return ($this->google_client_id ?? '') !== '' ? $this->google_client_id : null;
    }

    /** Whether customers may sign up with an email and a password; the accounts made keep signing in whatever it says. */
    public function allowsEmailSignUp(): bool
    {
        return $this->email_signup;
    }

    public function captchaDriver(): ?string
    {
        return ($this->captcha_driver ?? '') !== '' ? $this->captcha_driver : null;
    }

    public function captchaConfig(): array
    {
        return $this->captcha_config ?? [];
    }

    /** @return list<string> What the website lets the shop's admins do beyond the shop's daily work (`staff_grants`): StaffGrant values, in their order. */
    public function staffGrants(): array
    {
        return StaffGrant::among($this->staff_grants ?? []);
    }

    /** The hosts of the site's address and of the origins it lists. */
    public function captchaHosts(): array
    {
        $hosts = [];
        foreach ([(string) $this->url, ...$this->origins ?? []] as $address) {
            $host = Origin::of($address) === null ? null : parse_url($address, PHP_URL_HOST);
            if (is_string($host) && !in_array(strtolower($host), $hosts, true)) {
                $hosts[] = strtolower($host);
            }
        }

        return $hosts;
    }
}
