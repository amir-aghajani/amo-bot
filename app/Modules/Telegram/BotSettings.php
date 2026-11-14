<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Gates\PhoneMoment;
use App\Support\Persian;

/**
 * The bot's own runtime settings (each bot its own), as groups of the bot settings screen: `general` (the master switch
 * — a Gate —, the phone rule — Gates\PhoneRequirement: whether customers must share their number, and when: before
 * anything at all, or only at the moments chosen, and whether only Iranian numbers (+98) count —, the support contact,
 * and whether a message support writes to a customer comes under «پیام از طرف پشتیبانی»), `channels` (the
 * required-channels rule) and `qr` (whether a delivered service comes as a QR card).
 */
final class BotSettings implements DeclaresSettings
{
    /** The phone rule's reach: before anything at all, or only at the moments switched on. */
    public const PHONE_EVERYWHERE = 'everywhere';
    public const PHONE_MOMENTS = 'moments';

    private readonly Toggle $enabled;
    private readonly Toggle $phoneRequired;
    private readonly Choice $phoneScope;
    /** @var array<string, Toggle> By PhoneMoment value */
    private readonly array $phoneMoments;
    private readonly Toggle $phoneIranOnly;
    private readonly Text $supportContact;
    private readonly Toggle $supportHeader;
    private readonly Toggle $joinRequired;
    private readonly Toggle $qrEnabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('enabled', 'bot.enabled', default: true);
        $this->phoneRequired = new Toggle('phone_required', 'bot.phone_required', default: false);
        $this->phoneScope = new Choice('phone_scope', 'bot.phone_scope', self::PHONE_EVERYWHERE, [self::PHONE_EVERYWHERE, self::PHONE_MOMENTS], 'محدوده شماره موبایل باید «کل ربات» یا «فقط موقع این کارها» باشد.');
        $moments = [];
        foreach (PhoneMoment::cases() as $moment) {
            // All four on: «فقط موقع این کارها» chosen asks at every one of them until some are switched off.
            $moments[$moment->value] = new Toggle('phone_on_' . $moment->value, 'bot.phone_on_' . $moment->value, default: true);
        }
        $this->phoneMoments = $moments;
        $this->phoneIranOnly = new Toggle('phone_iran_only', 'bot.phone_iran_only', default: false);
        $this->supportContact = new Text('support_contact', 'bot.support_contact', default: '', label: 'راه ارتباط با پشتیبانی', max: 190);
        $this->supportHeader = new Toggle('support_header', 'bot.support_header', default: true);
        $this->joinRequired = new Toggle('join_required', 'bot.join_required', default: false);
        $this->qrEnabled = new Toggle('qr_enabled', 'bot.qr_enabled', default: true);
    }

    public function groups(): array
    {
        return [
            new Form('general', [$this->enabled, $this->phoneRequired, $this->phoneScope, ...array_values($this->phoneMoments), $this->phoneIranOnly, $this->supportContact, $this->supportHeader]),
            new Form('channels', [$this->joinRequired]),
            new Form('qr', [$this->qrEnabled]),
        ];
    }

    /** The master switch: off, and every customer gets BotText::BotOff instead of an answer (admins still get through). */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** Whether the shop asks customers for their Telegram phone number at all (when: phoneEverywhere(), phoneMoments()). */
    public function phoneRequired(): bool
    {
        return $this->settings->read($this->phoneRequired);
    }

    /** Whether the number, while asked, is asked before the bot serves anything at all (else at phoneMoments() only). */
    public function phoneEverywhere(): bool
    {
        return $this->settings->read($this->phoneScope) !== self::PHONE_MOMENTS;
    }

    /**
     * The moments the number is asked at while it is not asked before everything (phoneEverywhere()).
     *
     * @return list<PhoneMoment>
     */
    public function phoneMoments(): array
    {
        return array_values(array_filter(PhoneMoment::cases(), fn(PhoneMoment $moment): bool => $this->settings->read($this->phoneMoments[$moment->value])));
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

    /** Whether the phone rule takes Iranian numbers alone (+98): another country's is refused, and one on file counts as none. */
    public function phoneIranOnly(): bool
    {
        return $this->settings->read($this->phoneIranOnly);
    }

    /**
     * Whether a message support writes to a customer by hand («ارسال پیام») comes under the bot text «پیام پشتیبانی به
     * مشتری» (BotText::SupportMessage: «📩 پیام از طرف پشتیبانی:»); off, the customer gets the words alone.
     */
    public function supportHeader(): bool
    {
        return $this->settings->read($this->supportHeader);
    }

    /**
     * The support contact as a link for the website: an @handle as its t.me address, a web or t.me address (https added
     * to a bare t.me one), a phone number as tel:; null when unset or none of these.
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

    /** The support contact as a link a bot's button opens — a web or Telegram address (supportUrl()); null for a phone number. */
    public function supportChatUrl(): ?string
    {
        $url = $this->supportUrl();

        return $url !== null && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    /** Whether a delivered service comes with a QR code of its subscription link. */
    public function qrEnabled(): bool
    {
        return $this->settings->read($this->qrEnabled);
    }
}
