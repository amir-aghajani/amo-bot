<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Rewards\Services\GiftCodeSettings;
use App\Modules\Rewards\Services\WheelSettings;
use App\Modules\Subscriptions\Services\TestAccountSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;

/**
 * The bot's main menu. The admin lays it out (KeyboardLayouts, "start"): which actions, in which rows, as a reply
 * keyboard or inline buttons. This class knows the actions (ACTIONS), renders the layout for a customer (hiding what is
 * switched off), and is the one place that knows which form it takes — show() sends it, screen() puts a screen it
 * opens in place of the tapped message.
 */
final class MainMenu
{
    /** What every menu screen's callback starts with — a tap on one is navigation (the dispatcher drops any flow). */
    public const PREFIX = 'menu:';

    public const PLANS = 'menu:plans';
    public const TEST = 'menu:test';
    public const SUBSCRIPTIONS = 'menu:subs';
    public const RENEW = 'menu:renew';
    public const WALLET = 'menu:wallet';
    public const AFFILIATES = 'menu:affiliates';
    public const AGENCY = 'menu:agency';
    public const TUTORIAL = 'menu:tutorial';
    public const SUPPORT = 'menu:support';
    public const GIFT = 'menu:gift';
    public const WHEEL = 'menu:wheel';
    public const HOME = 'menu:home';

    /** Every action a menu button can stand for: key => admin-facing title, default label, the screen it opens. */
    public const ACTIONS = [
        'buy' => ['title' => 'خرید اشتراک', 'label' => Messages::MENU_BUY, 'screen' => self::PLANS],
        'test' => ['title' => 'اکانت تست', 'label' => Messages::MENU_TEST, 'screen' => self::TEST],
        'services' => ['title' => 'سرویس‌های من', 'label' => Messages::MENU_SERVICES, 'screen' => self::SUBSCRIPTIONS],
        'renew' => ['title' => 'تمدید سرویس', 'label' => Messages::MENU_RENEW, 'screen' => self::RENEW],
        'wallet' => ['title' => 'کیف پول + شارژ', 'label' => Messages::MENU_WALLET, 'screen' => self::WALLET],
        'affiliates' => ['title' => 'زیرمجموعه‌گیری', 'label' => Messages::MENU_AFFILIATES, 'screen' => self::AFFILIATES],
        'agency' => ['title' => 'نمایندگی', 'label' => Messages::MENU_AGENCY, 'screen' => self::AGENCY],
        'tutorial' => ['title' => 'آموزش', 'label' => Messages::MENU_TUTORIAL, 'screen' => self::TUTORIAL],
        'support' => ['title' => 'پشتیبانی', 'label' => Messages::MENU_SUPPORT, 'screen' => self::SUPPORT],
        'gift' => ['title' => 'کد هدیه', 'label' => Messages::MENU_GIFT, 'screen' => self::GIFT],
        'wheel' => ['title' => 'گردونه شانس', 'label' => Messages::MENU_WHEEL, 'screen' => self::WHEEL],
    ];

    public function __construct(
        private readonly KeyboardLayouts $layouts,
        private readonly ReferralSettings $referrals,
        private readonly AgencySettings $agency,
        private readonly GiftCodeSettings $giftCodes,
        private readonly WheelSettings $wheel,
        private readonly TestAccountSettings $tests,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /**
     * The layout a fresh shop starts with (rows in reading order, right to left):
     *   [ خرید اشتراک ]
     *   [ اکانت تست ]                 — only while free test accounts are switched on (markup())
     *   [ سرویس‌های من | تمدید سرویس ]
     *   [ آموزش | کیف پول + شارژ ]
     *   [ پشتیبانی | زیرمجموعه‌گیری ] — «زیرمجموعه‌گیری» only while the referral program runs (markup())
     *   [ نمایندگی ]                  — only while the agency program runs; never in an agent's bot (absent())
     *   [ کد هدیه | گردونه شانس ]     — each only while it is switched on (markup())
     */
    public static function defaultLayout(): KeyboardLayout
    {
        $button = static fn(string $action): array => ['action' => $action, 'label' => self::ACTIONS[$action]['label'], 'style' => null, 'icon' => null];

        return (new KeyboardLayout(KeyboardLayout::TYPE_REPLY, [
            [$button('buy')],
            [$button('test')],
            [$button('services'), $button('renew')],
            [$button('tutorial'), $button('wallet')],
            [$button('support'), $button('affiliates')],
            [$button('agency')],
            [$button('gift'), $button('wheel')],
        ]))->without(...self::absent());
    }

    /**
     * The actions the current shop's menu never has: an agent's bot has no «نمایندگی».
     *
     * @return list<string>
     */
    public static function absent(): array
    {
        return CurrentBot::isMain() ? [] : ['agency'];
    }

    /** @return list<array{key: string, title: string, label: string, screen: string}> The current shop's, in the picker's order. */
    public static function actions(): array
    {
        $list = [];
        foreach (self::ACTIONS as $key => $action) {
            if (!in_array($key, self::absent(), true)) {
                $list[] = ['key' => $key] + $action;
            }
        }

        return $list;
    }

    /** Whether a menu button can stand for `$key` at all (in any shop). */
    public static function hasAction(string $key): bool
    {
        return isset(self::ACTIONS[$key]);
    }

    /** The `menu:*` callback an action's inline button carries. */
    public static function callbackFor(string $action): string
    {
        return self::ACTIONS[$action]['screen'] ?? self::HOME;
    }

    /** The menu as the admin laid it out. */
    public function layout(): KeyboardLayout
    {
        return $this->layouts->get(KeyboardLayouts::START);
    }

    /** Whether the menu is inline buttons rather than a reply keyboard. */
    public function isInline(): bool
    {
        return $this->layout()->isInline();
    }

    /**
     * Send `$text` with the menu. A reply keyboard stays on the client until a message removes it and a message carries
     * one markup, so switching to an inline menu takes two messages: `$text` removes the keyboard, the menu follows.
     */
    public function show(Context $ctx, string $text): void
    {
        if (!$this->isInline()) {
            $ctx->reply($text, ['reply_markup' => $this->markup($ctx->user)]);
            $ctx->session->markReplyKeyboard(true);

            return;
        }

        if ($ctx->session->showsReplyKeyboard()) {
            $ctx->reply($text, ['reply_markup' => ReplyKeyboard::remove()]);
            $ctx->session->markReplyKeyboard(false);
            $text = $this->texts->get(BotText::MenuPrompt);
        }
        $ctx->reply($text, ['reply_markup' => $this->markup($ctx->user)]);
    }

    /**
     * A screen the menu opens, in place of the tapped message — or, `$fresh`, as a new message (under the greeting a
     * /start link opened); with an inline menu it also gets «بازگشت» to the menu, since nothing under the text field
     * leads back.
     */
    public function screen(Context $ctx, string $text, ?InlineKeyboard $keyboard = null, ?string $backStyle = null, bool $fresh = false): void
    {
        if ($this->isInline()) {
            $keyboard = ($keyboard ?? InlineKeyboard::make())->row($this->buttons->back(self::HOME, $backStyle));
        }

        $options = $keyboard === null ? [] : ['reply_markup' => $keyboard->build()];
        if ($fresh) {
            $ctx->reply($text, $options);
        } else {
            $ctx->edit($text, $options);
        }
    }

    /**
     * The menu's `reply_markup` for a customer, without the buttons of programs switched off (an agent keeps
     * «نمایندگی»: it is their account). They stay on the admin's layout and come back with their program.
     *
     * @return array<string, mixed>
     */
    public function markup(User $user): array
    {
        $off = array_keys(array_filter([
            'affiliates' => !$this->referrals->enabled(),
            'agency' => !$this->agency->enabled() && !$user->isAgent(),
            'gift' => !$this->giftCodes->enabled(),
            'wheel' => !$this->wheel->enabled(),
            'test' => !$this->tests->enabled(),
        ]));

        return $this->layout()->without(...$off)->markup();
    }

    /** @return list<string> The reply-keyboard labels to route as messages. */
    public function labels(): array
    {
        return $this->layout()->labels();
    }

    /** The screen a keyboard label opens (null for any other text). */
    public function screenFor(?string $text): ?string
    {
        $action = $this->layout()->actionFor($text);

        return $action === null ? null : self::callbackFor($action);
    }
}
