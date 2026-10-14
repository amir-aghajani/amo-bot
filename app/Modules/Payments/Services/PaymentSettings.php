<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Ruled;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\BotSettings;

/**
 * How card-to-card payments are taken (the `payments` group): whether the message with the card number may be
 * forwarded or saved, whether the customer is sent to support's private chat instead of being shown a card (refused,
 * and not done, while the bot has no support contact to send them to), and whether a customer may send another receipt
 * while one waits for review.
 */
final class PaymentSettings implements DeclaresSettings
{
    /** Support's private chat without a way to reach it. */
    public const NO_CONTACT = 'اول راه ارتباط با پشتیبانی را در بخش «عمومی» بنویسید؛ مشتری برای پرداخت کارت به کارت به همان‌جا فرستاده می‌شود.';

    private readonly Toggle $protectCard;
    /** @var Field<bool> */
    private readonly Field $toSupport;
    private readonly Toggle $singleReceipt;

    public function __construct(private readonly Settings $settings, private readonly BotSettings $bot)
    {
        $this->protectCard = new Toggle('card_protect', 'payments.card_protect', default: true);
        $this->toSupport = new Ruled(
            new Toggle('card_to_support', 'payments.card_to_support', default: false),
            fn(array $values): ?string => $values['card_to_support'] === true && trim($this->bot->supportContact()) === '' ? self::NO_CONTACT : null,
        );
        $this->singleReceipt = new Toggle('receipt_single', 'payments.receipt_single', default: true);
    }

    public function groups(): array
    {
        return [new Form('payments', [$this->protectCard, $this->toSupport, $this->singleReceipt])];
    }

    /** Whether the bot's message with the card number is sent protected — no forwarding, no saving. */
    public function protectsCard(): bool
    {
        return $this->settings->read($this->protectCard);
    }

    /**
     * Whether a card-to-card payment sends the customer to support's private chat instead of showing a card — while the
     * bot has a support contact to send them to: one emptied since (in «عمومی») shows the card again, no dead end.
     */
    public function sendsToSupport(): bool
    {
        return $this->settings->read($this->toSupport) && trim($this->bot->supportContact()) !== '';
    }

    /** Whether a customer must wait for their receipt's review before sending another. */
    public function oneReceiptAtATime(): bool
    {
        return $this->settings->read($this->singleReceipt);
    }
}
