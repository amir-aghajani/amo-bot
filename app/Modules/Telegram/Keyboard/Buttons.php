<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The buttons shared by many screens, in the admin's wording: «⬅️ بازگشت», «بعدی» / «قبلی», «➕ افزایش موجودی»,
 * «💾 خرید حجم», «📊 مشاهده سرویس», «💬 پیام به پشتیبانی».
 */
final class Buttons
{
    public function __construct(
        private readonly BotTexts $texts,
        private readonly BotSettings $bot,
    ) {}

    /**
     * «⬅️ بازگشت» to a screen — its callback data.
     *
     * @param string|null $style One of KeyboardLayout::STYLES; null = the client's own look
     * @return array<string, mixed>
     */
    public function back(string $screen, ?string $style = null): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::Back), $screen, $style);
    }

    /**
     * A keyboard that is just «بازگشت» to a screen: what a dead end carries (a plan gone, an amount refused).
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function backOnly(string $screen): array
    {
        return InlineKeyboard::make()->row($this->back($screen))->build();
    }

    /**
     * «بعدی» and «قبلی» under a paged list, each only where there is a page to go to. Forward is leftward for a
     * right-to-left reader, so «بعدی» comes first in the row.
     *
     * @param \Closure(int): string $at The list at another page
     * @return list<array<string, mixed>> The row's buttons; none for a list of one page
     */
    public function pages(int $page, int $pages, \Closure $at): array
    {
        $row = [];
        if ($page < $pages) {
            $row[] = InlineKeyboard::callback($this->texts->get(BotText::PageNext), $at($page + 1));
        }
        if ($page > 1) {
            $row[] = InlineKeyboard::callback($this->texts->get(BotText::PagePrev), $at($page - 1));
        }

        return $row;
    }

    /**
     * «➕ افزایش موجودی»: the wallet's top-up.
     *
     * @return array<string, mixed>
     */
    public function topUp(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::WalletTopup), TopUpHandler::START, 'success');
    }

    /**
     * A keyboard that is just «📊 مشاهده سرویس», the way to a service's own screen under a message about it (a delivery,
     * a notice).
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function serviceOnly(int $subscriptionId): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::ReminderOpenService), SubscriptionHandler::serviceCallback($subscriptionId)))->build();
    }

    /**
     * The answers to a question about something that cannot be taken back (a link changed, a service handed on, money
     * sent), in one row: «انصراف» back to `$cancelTo`, and the red `$yes` to `$confirm`. Telegram lays a row out left to
     * right, so "yes" is read first, on the right.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function confirm(string $cancelTo, BotText $yes, string $confirm): array
    {
        return InlineKeyboard::make()->row(
            InlineKeyboard::callback($this->texts->get(BotText::Cancel), $cancelTo),
            InlineKeyboard::callback($this->texts->get($yes), $confirm, 'danger'),
        )->build();
    }

    /**
     * confirm() for a change of a service («تغییر لینک», «انتقال سرویس»): «انصراف» back to the service's screen.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function confirmChange(int $subscriptionId, BotText $yes, string $confirm): array
    {
        return $this->confirm(SubscriptionHandler::serviceCallback($subscriptionId), $yes, $confirm);
    }

    /**
     * «💬 پیام به پشتیبانی», opening support's private chat, under a card payment taken there: while the support contact
     * is a web or Telegram link (BotSettings::supportChatUrl()) — a phone number is no button, the words say where to go.
     *
     * @return list<array<string, mixed>> The row's button; none without such a link
     */
    public function supportChat(): array
    {
        $url = $this->bot->supportChatUrl();

        return $url === null ? [] : [InlineKeyboard::url($this->texts->get(BotText::CardToSupportButton), $url)];
    }

    /**
     * «💾 خرید حجم»: an agent's traffic purchase.
     *
     * @return array<string, mixed>
     */
    public function buyTraffic(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::AgencyBuyTraffic), AgencyHandler::TRAFFIC, 'success');
    }
}
