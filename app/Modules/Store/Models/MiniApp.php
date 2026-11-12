<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Store\Contracts\Door;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A shop's Telegram mini app, one per bot (made by Services\MiniApps): its switch, its own store key — the app's address
 * names it, and the Store API opens under it —, how it looks, its announcement, and the bot's menu button that opens it.
 * As a sign-in site it takes no website way in: a customer signs in by the launch data Telegram signs with the bot's
 * token (Accounts\Services\MiniAppSignIn).
 *
 * @property int $id
 * @property string $key The store key: 24 lower-case hex characters — it names the shop, it is no secret
 * @property bool $enabled
 * @property string|null $title The name the app shows; null — the shop's (Bots::name())
 * @property string|null $accent_color The app's colour (#rrggbb); null — the Telegram client's button colour
 * @property string|null $logo_path The logo's file under storage/uploads/logos; null for none
 * @property string|null $announcement A word to every customer on the app's home screen; null for none
 * @property string|null $announcement_url Where the announcement leads (an https address: a t.me link is one); null for nowhere
 * @property bool $fullscreen Whether it opens full screen on a phone
 * @property bool $reviews_enabled Whether it shows the reviews support approved and takes new ones
 * @property bool $menu_button Whether the bot's menu button (beside the message box) opens it
 * @property string|null $menu_button_text That button's words; null — MiniApps::MENU_BUTTON_TEXT
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MiniApp extends Model implements Door
{
    use BelongsToBot;

    protected $table = 'mini_apps';

    protected $fillable = [
        'key',
        'enabled',
        'title',
        'accent_color',
        'logo_path',
        'announcement',
        'announcement_url',
        'fullscreen',
        'reviews_enabled',
        'menu_button',
        'menu_button_text',
    ];

    /** A mini app starts switched off, opening full screen and with the menu button once switched on. */
    protected $attributes = [
        'enabled' => false,
        'fullscreen' => true,
        'reviews_enabled' => false,
        'menu_button' => true,
    ];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
        'fullscreen' => 'boolean',
        'reviews_enabled' => 'boolean',
        'menu_button' => 'boolean',
    ];

    public function storeKey(): string
    {
        return $this->key;
    }

    public function showsReviews(): bool
    {
        return $this->reviews_enabled;
    }

    /** The app is on the shop's own address: no page elsewhere calls the API as it. */
    public function allowsOrigin(string $origin): bool
    {
        return false;
    }

    public function isMiniApp(): bool
    {
        return true;
    }

    public function telegramClientId(): ?string
    {
        return null;
    }

    public function hasTelegramSecret(): bool
    {
        return false;
    }

    public function telegramClientSecret(): ?string
    {
        return null;
    }

    public function googleClientId(): ?string
    {
        return null;
    }

    public function allowsEmailSignUp(): bool
    {
        return false;
    }

    public function allowsPhoneSignIn(): bool
    {
        return false;
    }

    public function captchaDriver(): ?string
    {
        return null;
    }

    public function captchaConfig(): array
    {
        return [];
    }

    public function captchaHosts(): array
    {
        return [];
    }
}
