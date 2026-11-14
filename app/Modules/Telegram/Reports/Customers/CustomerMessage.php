<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Modules\Auth\Actor;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Telegram\Reports\UserManagement;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;

/**
 * «✉️ ارسال پیام» on a customer's card in the report group (CustomerCards: the checks, the questions): the admin's reply
 * to the bot's question is sent to the customer from support, as the panel's «ارسال پیام» sends it
 * (UserActions::message(), under the admin's name) — under «📩 پیام از طرف پشتیبانی:» while the bot settings say so —,
 * and the card's front comes back, with what came of it under the reply. Never to the admin's own account: the card
 * offers it on every other.
 */
final class CustomerMessage implements GroupHandler, AnswersPrompts
{
    /** What the section's button's callback data starts with: `ux:a:<customer id>`. */
    public const PREFIX = 'ux:';

    /** The message asked — and the question's kind, which the reply comes back with. */
    public const ASK = 'a';

    private const QUESTION = '✉️ متن پیام را در پاسخ به همین پیام بنویسید؛ از طرف پشتیبانی با ربات برایش فرستاده می‌شود.';
    private const PLACEHOLDER = 'متن پیام';
    private const OWN = 'برای خودتان پیام نمی‌فرستید.';

    public function __construct(
        private readonly CustomerCards $cards,
        private readonly UserActions $actions,
    ) {}

    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        if ($press->action !== self::ASK || $press->args !== []) {
            $this->cards->answer($press);

            return;
        }
        if ($press->admin->id === $press->user->id) {
            $this->cards->answer($press, self::OWN, alert: true);

            return;
        }

        $this->cards->ask($press, self::PREFIX, self::ASK, [], self::QUESTION, self::PLACEHOLDER);
    }

    /**
     * The admin's reply: sent, the card's front back, and what came of it under the reply; too long — or about the
     * replying admin's own account (another admin's question) —, asked again.
     */
    public function answered(CardReply $reply): void
    {
        if ($reply->kind !== self::ASK) {
            return;
        }
        if ($reply->admin->id === $reply->user->id) {
            $this->cards->retry($reply, self::OWN);

            return;
        }

        $delivery = $this->actions->message($reply->user, Actor::groupAdmin($reply->admin), ['text' => $reply->text]);
        $this->cards->done($reply, self::outcome($delivery, $reply->user), UserManagement::card($reply->user, $reply->admin), UserManagement::main($reply->user, $reply->admin));
    }

    /**
     * The button on the card's front.
     *
     * @return array<string, mixed>
     */
    public static function button(int $userId): array
    {
        return CustomerCards::button('✉️ ارسال پیام', CustomerCards::data(self::PREFIX, self::ASK, $userId));
    }

    /**
     * What came of the message, as the admin reads it under their reply — in the panel's words: without Telegram, it was
     * the email that went or failed; with an email, a message that went nowhere means the shop's email is not set up.
     */
    private static function outcome(Delivery $delivery, User $user): string
    {
        $telegram = $user->telegram_id !== null;

        return match ($delivery) {
            Delivery::Told => '✅ پیام در تلگرام به او رسید.',
            Delivery::Emailed => $telegram ? '📧 در تلگرام در دسترس نبود؛ پیام به ایمیلش رفت.' : '📧 پیام به ایمیلش رفت.',
            Delivery::TurnedAway => '⚠️ ربات را بلاک کرده؛ پیام در تلگرام به او نرسید و فقط در وب‌سایتش می‌ماند.',
            Delivery::Unreachable => $telegram ? '⚠️ تلگرام در دسترس نبود و پیام نرسید؛ کمی بعد دوباره بفرستید.' : '⚠️ سرور ایمیل در دسترس نبود و پیام نرسید؛ کمی بعد دوباره بفرستید.',
            Delivery::Refused => '⚠️ تلگرام پیام را نپذیرفت.',
            Delivery::NoTelegram => ($user->email ?? '') !== '' ? '⚠️ تلگرام ندارد و ایمیل هم برایش نرفت؛ پیام فقط در وب‌سایتش می‌ماند.' : '⚠️ تلگرام و ایمیل ندارد؛ پیام فقط در وب‌سایتش می‌ماند.',
        };
    }
}
