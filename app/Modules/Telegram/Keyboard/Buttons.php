<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Support\Money;
use App\Support\Persian;

/**
 * The buttons shared by many screens, in the admin's wording: «⬅️ بازگشت», «بعدی» / «قبلی» under a list of several
 * pages, «➕ افزایش موجودی», «💾 خرید حجم», «📊 مشاهده سرویس», «💬 پیام به پشتیبانی», a plan's and a location's in a
 * list to pick from.
 */
final class Buttons
{
    /**
     * The buttons a page of a list the shop makes as long as it likes holds (its plans, categories, servers): a list is
     * paged only past one keyboard's worth — Telegram's KEYBOARD_BUTTONS, less room for the rows under the list (the
     * pages, a way back, a few more).
     */
    public const LIST_PAGE = Limits::KEYBOARD_BUTTONS - 10;

    public function __construct(
        private readonly BotTexts $texts,
        private readonly BotSettings $bot,
    ) {}

    /**
     * The page a list of `$total` items is shown at — `$page`, or the first or the last for one past either end (a list
     * that shrank since its screen was drawn) — and how many pages it makes, `$perPage` a page.
     *
     * @return array{int, int} The page, and the pages
     */
    public static function page(int $total, int $page, int $perPage): array
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));

        return [max(1, min($page, $pages)), $pages];
    }

    /**
     * The items of `$items` on its page `$page` (page()), with the page and how many pages there are.
     *
     * @template T
     * @param list<T> $items
     * @return array{list<T>, int, int}
     */
    public static function slice(array $items, int $page, int $perPage = self::LIST_PAGE): array
    {
        [$page, $pages] = self::page(count($items), $page, $perPage);

        return [array_slice($items, ($page - 1) * $perPage, $perPage), $page, $pages];
    }

    /**
     * Under a page of a list of several: «بعدی» / «قبلی» as the keyboard's next row, each only where there is a page to
     * go to — forward is leftward for a right-to-left reader, so «بعدی» comes first —, and `$text` with where the page
     * is among them («صفحه ۲ از ۳»). A list of one page is left as it is.
     *
     * @param \Closure(int): string $at The list at another page
     */
    public function paged(InlineKeyboard $keyboard, string $text, int $page, int $pages, \Closure $at): string
    {
        if ($pages < 2) {
            return $text;
        }

        $row = [];
        if ($page < $pages) {
            $row[] = InlineKeyboard::callback($this->texts->get(BotText::PageNext), $at($page + 1));
        }
        if ($page > 1) {
            $row[] = InlineKeyboard::callback($this->texts->get(BotText::PagePrev), $at($page - 1));
        }
        $keyboard->row(...$row);

        return $text . $this->texts->part(BotText::SubscriptionsPage, ['page' => Persian::digits($page), 'pages' => Persian::digits($pages)]);
    }

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
     * A plan's button in a list to pick it from — «name — traffic / term — price», at the customer's `$price` — in the
     * colour its plan's form gives it, with how many share its row (InlineKeyboard::flow()).
     *
     * @return array{array<string, mixed>, int}
     */
    public function plan(Plan $plan, string $price, string $callback): array
    {
        $label = $this->texts->render(BotText::PlanButton, [
            'plan' => $plan->name,
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'duration' => Messages::duration($plan->duration_hours),
            'price' => Money::format($price),
        ]);

        return [InlineKeyboard::callback($label, $callback, KeyboardLayout::style($plan->button_style)), $plan->button_row];
    }

    /**
     * A location's button «🌍 …» in its server's colour, with how many share its row (InlineKeyboard::flow()).
     *
     * @return array{array<string, mixed>, int}
     */
    public function server(Server $server, string $callback): array
    {
        return [InlineKeyboard::callback($this->texts->render(BotText::ServerButton, ['server' => $server->name]), $callback, KeyboardLayout::style($server->buttonStyle())), $server->buttonRow()];
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
