<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Enums\UserRole;

/**
 * /start, /menu and the contact card the phone gate just took (ContactHandler): the greeting and the main menu
 * (MainMenu::show()), then the screen a /start link opens — `?start=usertest` the free test. A link a gate held back
 * waits in the chat for an hour (ChatSession::takeStartLink()) and opens with the update that gets through: the contact
 * card, /start or /menu here, or «عضو شدم» (JoinHandler).
 */
final class StartHandler implements Handler
{
    public const OWNER_LINKED = '👑 این حساب تلگرام حالا مالک فروشگاه است: «⚙️ مدیریت» را بزنید، یا /admin بفرستید.';
    public const OWNER_LINK_DEAD = 'این لینک دیگر کار نمی‌کند (یک بار و تا ۱۰ دقیقه کار می‌کند)؛ از پنل، «تنظیمات پنل › ورود به پنل»، لینک تازه‌ای بسازید.';

    public function __construct(
        private readonly MainMenu $menu,
        private readonly BotTexts $texts,
        private readonly TestAccountHandler $tests,
        private readonly OwnerLinks $owners,
    ) {}

    public function handle(Context $ctx): void
    {
        $kept = $ctx->session->takeStartLink();
        $this->open($ctx, $ctx->update->commandArgument() ?? $kept);
    }

    /**
     * The owner's one-time link (`owner-<code>`, OwnerLinks), opened in the main bot: the account is the owner from now
     * on — and the bot's admin, as an agent is in their own bot, so the bot's switch, phone and channels never stop them
     * and the report group's buttons answer them —, then the menu with «⚙️ مدیریت». A link spent, out of time or opened
     * in an agent's bot says so.
     */
    private function ownerLink(Context $ctx, string $link): void
    {
        $user = $ctx->user;
        if (!CurrentBot::isMain() || $user->telegram_id === null || !$this->owners->redeem($link, $user->telegram_id)) {
            $this->menu->show($ctx, self::OWNER_LINK_DEAD);

            return;
        }
        if (!$user->isAdmin()) {
            $user->role = UserRole::Admin;
            $user->save();
            // The link's role: it goes with the link.
            $this->owners->raised($user->telegram_id);
        }
        $this->menu->show($ctx, self::OWNER_LINKED);
    }

    /**
     * The greeting and the menu, then the screen the /start link `$link` opens, as a new message under them — the owner's
     * link (kept while a gate held its opener back: «✅ عضو شدم») links as /start does.
     */
    public function open(Context $ctx, ?string $link): void
    {
        if (OwnerLinks::isLink($link)) {
            $this->ownerLink($ctx, (string) $link);

            return;
        }
        $this->menu->show($ctx, $this->texts->welcome($ctx->user->name()));
        if ($link === TestAccountHandler::START_LINK) {
            $this->tests->home($ctx, fresh: true);
        }
    }
}
