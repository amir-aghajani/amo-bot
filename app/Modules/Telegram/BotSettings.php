<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Support\Persian;

/**
 * How the bot itself behaves, as the admin sets it at runtime (every bot its own; read per update, so a change reaches
 * the running bot at once) — its groups of the bot settings screen: `general` (the master switch and the phone rule,
 * each enforced by a Gate the dispatcher runs before any handler, and the support contact the bot's «پشتیبانی» screen
 * shows), `channels` (the channel rule, beside the list it applies: Channels\RequiredChannels) and `qr` (whether a
 * delivered service comes as a QR card; its picture is Qr\QrBackground's, uploaded rather than saved here).
 */
final class BotSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;
    private readonly Toggle $phoneRequired;
    private readonly Text $supportContact;
    private readonly Toggle $joinRequired;
    private readonly Toggle $qrEnabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('enabled', 'bot.enabled', default: true);
        $this->phoneRequired = new Toggle('phone_required', 'bot.phone_required', default: false);
        $this->supportContact = new Text('support_contact', 'bot.support_contact', default: '', label: 'راه ارتباط با پشتیبانی', max: 190);
        $this->joinRequired = new Toggle('join_required', 'bot.join_required', default: false);
        $this->qrEnabled = new Toggle('qr_enabled', 'bot.qr_enabled', default: true);
    }

    public function groups(): array
    {
        return [
            new Form('general', [$this->enabled, $this->phoneRequired, $this->supportContact]),
            new Form('channels', [$this->joinRequired]),
            new Form('qr', [$this->qrEnabled]),
        ];
    }

    /** The master switch: off, and every customer gets BotText::BotOff instead of an answer (admins still get through). */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** Whether a customer must share their Telegram phone number before the bot serves them. */
    public function phoneRequired(): bool
    {
        return $this->settings->read($this->phoneRequired);
    }

    /** Whether a customer must be a member of every channel in Channels\RequiredChannels before the bot serves them. */
    public function joinRequired(): bool
    {
        return $this->settings->read($this->joinRequired);
    }

    /** How to reach support, as the bot's «پشتیبانی» screen shows it (a handle, a link, a number); "" when not set. */
    public function supportContact(): string
    {
        return $this->settings->read($this->supportContact);
    }

    /**
     * The support contact as a link a page opens (the shop's website): an @handle as its t.me address, a web or t.me
     * address as it is (https:// added to a bare t.me one), a phone number as a tel: link — null when none is set, or it
     * is none of these (words the bot shows as they are).
     */
    public function supportUrl(): ?string
    {
        $contact = trim($this->supportContact());
        $phone = Persian::latinDigits($contact);

        return match (true) {
            $contact === '' => null,
            preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,31})$/', $contact, $handle) === 1 => 'https://t.me/' . $handle[1],
            preg_match('~^https?://[^\s/?#]+\S*$~i', $contact) === 1 => $contact,
            preg_match('~^(?:t|telegram)\.me/\S+$~i', $contact) === 1 => 'https://' . $contact,
            preg_match('/^\+?\d[\d ()-]{6,20}$/', $phone) === 1 => 'tel:' . preg_replace('/[^\d+]/', '', $phone),
            default => null,
        };
    }

    /** Whether a delivered service comes with a QR code of its subscription link. */
    public function qrEnabled(): bool
    {
        return $this->settings->read($this->qrEnabled);
    }
}
