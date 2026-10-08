<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;

/**
 * The bot's home screen. Its layout — which buttons, in which rows, reply keyboard or inline — is
 * the admin's (`KeyboardLayouts`, "start"); this class knows the actions a button can stand for and
 * renders the current layout. Callback ids (`menu:*`) are what inline buttons and "back" buttons use;
 * a reply-keyboard tap arrives as the button's label and actionFor() maps it back. It also puts the menu on
 * the chat (show()) and the screens it opens (screen()), the one place that knows which of the two the layout is.
 */
final class MainMenu
{
    /** What every menu screen's callback starts with — a tap on one is navigation (the dispatcher drops any flow). */
    public const PREFIX = 'menu:';

    public const PLANS = 'menu:plans';
    public const SUBSCRIPTIONS = 'menu:subs';
    public const RENEW = 'menu:renew';
    public const WALLET = 'menu:wallet';
    public const AFFILIATES = 'menu:affiliates';
    public const AGENCY = 'menu:agency';
    public const TUTORIAL = 'menu:tutorial';
    public const SUPPORT = 'menu:support';
    public const HOME = 'menu:home';

    /**
     * Everything a menu button can do: key => admin-facing title, default label, the screen it opens.
     * Order = the order offered in the admin's picker. A layout kept from before with a button whose action is not here
     * (one the bot no longer has) reads without it (KeyboardLayouts).
     */
    public const ACTIONS = [
        'buy' => ['title' => 'خرید اشتراک', 'label' => Messages::MENU_BUY, 'screen' => self::PLANS],
        'services' => ['title' => 'سرویس‌های من', 'label' => Messages::MENU_SERVICES, 'screen' => self::SUBSCRIPTIONS],
        'renew' => ['title' => 'تمدید سرویس', 'label' => Messages::MENU_RENEW, 'screen' => self::RENEW],
        'wallet' => ['title' => 'کیف پول + شارژ', 'label' => Messages::MENU_WALLET, 'screen' => self::WALLET],
        'affiliates' => ['title' => 'زیرمجموعه‌گیری', 'label' => Messages::MENU_AFFILIATES, 'screen' => self::AFFILIATES],
        'agency' => ['title' => 'نمایندگی', 'label' => Messages::MENU_AGENCY, 'screen' => self::AGENCY],
        'tutorial' => ['title' => 'آموزش', 'label' => Messages::MENU_TUTORIAL, 'screen' => self::TUTORIAL],
        'support' => ['title' => 'پشتیبانی', 'label' => Messages::MENU_SUPPORT, 'screen' => self::SUPPORT],
    ];

    public function __construct(
        private readonly KeyboardLayouts $layouts,
        private readonly ReferralSettings $referrals,
        private readonly AgencySettings $agency,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /**
     * The layout a fresh shop starts with (rows in reading order, right to left):
     *   [ خرید اشتراک ]
     *   [ سرویس‌های من | تمدید سرویس ]
     *   [ آموزش | کیف پول + شارژ ]
     *   [ پشتیبانی | زیرمجموعه‌گیری ] — «زیرمجموعه‌گیری» only while the referral program runs (markup())
     *   [ نمایندگی ]                  — only while the agency program runs; never in an agent's bot (absent())
     */
    public static function defaultLayout(): KeyboardLayout
    {
        $button = static fn(string $action): array => ['action' => $action, 'label' => self::ACTIONS[$action]['label'], 'style' => null, 'icon' => null];

        return (new KeyboardLayout(KeyboardLayout::TYPE_REPLY, [
            [$button('buy')],
            [$button('services'), $button('renew')],
            [$button('tutorial'), $button('wallet')],
            [$button('support'), $button('affiliates')],
            [$button('agency')],
        ]))->without(...self::absent());
    }

    /**
     * The actions the current shop's menu never has: an agent's bot has no agency of its own — no «نمایندگی». Its
     * editor neither offers nor takes them (actions(), KeyboardLayouts), and a layout saved before reads without them.
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

    public static function hasAction(string $key): bool
    {
        return isset(self::ACTIONS[$key]);
    }

    /** The `menu:*` callback an action's inline button carries. */
    public static function callbackFor(string $action): string
    {
        return self::ACTIONS[$action]['screen'] ?? self::HOME;
    }

    public function layout(): KeyboardLayout
    {
        return $this->layouts->get(KeyboardLayouts::START);
    }

    public function isInline(): bool
    {
        return $this->layout()->isInline();
    }

    /**
     * The menu under `$text` (a greeting, "not understood") in a message of its own: a reply keyboard under the text
     * field, or inline buttons under the message. A reply keyboard stays on the client until a message takes it away,
     * and a message carries one markup: going to an inline menu, the text takes the old keyboard away and the menu
     * follows under BotText::MenuPrompt.
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
     * A screen the menu opens, in place of the tapped message (in a message of its own, from a reply keyboard): its own
     * buttons, and — with an inline menu, which leaves nothing under the text field to go back with — «بازگشت» to the
     * menu under them, in one of KeyboardLayout::STYLES (`$backStyle`) or the client's own look.
     */
    public function screen(Context $ctx, string $text, ?InlineKeyboard $keyboard = null, ?string $backStyle = null): void
    {
        if ($this->isInline()) {
            $keyboard = ($keyboard ?? InlineKeyboard::make())->row($this->buttons->back(self::HOME, $backStyle));
        }

        $ctx->edit($text, $keyboard === null ? [] : ['reply_markup' => $keyboard->build()]);
    }

    /**
     * The `reply_markup` that shows the menu — without «زیرمجموعه‌گیری» while the referral program is off, nor
     * «نمایندگی» while the agency program is (an agent keeps it: it is their account), so the buttons stay on the
     * admin's layout and come back with them. An agent's bot has no agency of its own: its layout never has «نمایندگی»
     * (absent()).
     *
     * @return array<string, mixed>
     */
    public function markup(User $user): array
    {
        $off = array_keys(array_filter([
            'affiliates' => !$this->referrals->enabled(),
            'agency' => !$this->agency->enabled() && !$user->isAgent(),
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
