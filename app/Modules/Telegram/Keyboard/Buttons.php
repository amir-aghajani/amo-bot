<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The buttons shared by many screens, in the admin's wording: «⬅️ بازگشت», «بعدی» / «قبلی», «➕ افزایش موجودی»,
 * «💾 خرید حجم», «📊 مشاهده سرویس».
 */
final class Buttons
{
    public function __construct(private readonly BotTexts $texts) {}

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
     * «💾 خرید حجم»: an agent's traffic purchase.
     *
     * @return array<string, mixed>
     */
    public function buyTraffic(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::AgencyBuyTraffic), AgencyHandler::TRAFFIC, 'success');
    }
}
