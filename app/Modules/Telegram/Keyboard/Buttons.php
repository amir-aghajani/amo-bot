<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The buttons the bot puts under more than one screen, in the admin's words (BotTexts) and looking the same wherever
 * they show: «⬅️ بازگشت» to a screen, «بعدی» / «قبلی» under a list of pages, «➕ افزایش موجودی», «💾 خرید حجم».
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
     * «بعدی» and «قبلی» under a list that runs over more than one page («سرویس‌های من», the renewals, «تیکت‌های من»):
     * none on its first page for «قبلی» nor on its last for «بعدی». Forward is leftward for a right-to-left reader, so
     * «بعدی» comes first — Telegram lays a row out left to right.
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
     * «➕ افزایش موجودی»: the wallet's top-up — under the wallet, a payment it cannot cover, an automatic renewal it
     * could not pay.
     *
     * @return array<string, mixed>
     */
    public function topUp(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::WalletTopup), TopUpHandler::START, 'success');
    }

    /**
     * «💾 خرید حجم»: an agent's traffic — under their account and a delivery their traffic could not cover.
     *
     * @return array<string, mixed>
     */
    public function buyTraffic(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::AgencyBuyTraffic), AgencyHandler::TRAFFIC, 'success');
    }
}
