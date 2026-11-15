<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Core\Support\FileLock;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Handlers\FallbackHandler;

/**
 * The shop's management in its bot's private chat: `/admin` (or `/panel`) and the «⚙️ مدیریت» button open it, its
 * buttons (Nav::PREFIX) move through its screens, and the chat's answers to its questions (Admin::INPUT) go to the
 * screen that asked. Only a seat (AdminSeats: the owner's linked account, an agent in their own bot, the shop's admins
 * while «مدیران ربات» lets them in) gets anything: anyone else is answered as an unknown command or button is.
 *
 * One message is the management's window: a tap edits it in place; a command, the button or a typed answer sends it
 * anew at the bottom of the chat and takes the last one away. A typed answer is deleted once read — a token, a password
 * or a card number typed into a settings card does not stay in the chat. A refusal of the panel's (ApiRefusal) is said
 * in a popup for a tap, and over the question for a typed answer, which still waits.
 */
final class AdminHandler implements Handler
{
    /** The menu button that opens it (MainMenu adds it for a seat). */
    public const LABEL = '⚙️ مدیریت';

    /** What the chat keeps of the window: its message's id. */
    private const MESSAGE = 'msg';

    /**
     * @param string $turns The folder of the chats' turns (a lock file a chat)
     */
    public function __construct(
        private readonly AdminSeats $seats,
        private readonly PanelBridge $bridge,
        private readonly Screens $screens,
        private readonly FallbackHandler $fallback,
        private readonly string $turns,
    ) {}

    /**
     * The update in the chat's turn: a second tap at once (a webhook takes both together) waits for the first, then reads
     * the chat as the first left it — a draft the first saved and ended is gone for it, so nothing is done twice. What the
     * screens kept is saved whatever came of the update: a change the panel made and a Telegram call that failed after it
     * leave no draft to make it again.
     */
    public function handle(Context $ctx): void
    {
        $turn = FileLock::wait($this->turns . '/' . CurrentBot::id() . '-' . $ctx->chatId() . '.lock');
        try {
            $ctx->session->reload();
            // A tap, a command or the button is navigation: the flow the Dispatcher left — read again just now — is left,
            // a question waiting too. Any other message at a question is its answer.
            if ($ctx->update->isCallback() || $ctx->update->isCommand() || !$ctx->session->inState(Admin::INPUT) || trim((string) $ctx->update->text()) === self::LABEL) {
                $ctx->session->clear();
            }
            $this->serve($ctx);
        } finally {
            $ctx->session->save();
            $turn?->release();
        }
    }

    private function serve(Context $ctx): void
    {
        $fact = $ctx->session->get(Admin::FACT, []);
        $shop = is_array($fact) && is_int($fact['shop'] ?? null) ? $fact['shop'] : null;
        $seat = $this->seats->of($ctx->user, $shop);
        if ($seat === null) {
            if ($ctx->session->inState(Admin::INPUT)) {
                $ctx->session->clear();
            }
            $this->fallback->handle($ctx);

            return;
        }
        $admin = new Admin($ctx, $seat, $this->bridge->for($seat), $this->screens);

        if ($ctx->update->isCallback()) {
            $this->tapped($admin);
        } elseif ($ctx->session->inState(Admin::INPUT)) {
            $this->answered($admin);
        } else {
            $this->show($admin, $this->screens->home()->show($admin, []), fresh: true);
        }
    }

    /**
     * A button of the window pressed: its screen's action, shown in place. Any question waiting was left (handle()): what
     * is typed next is no answer to it.
     */
    private function tapped(Admin $admin): void
    {
        $ctx = $admin->ctx;
        $route = Nav::parse($ctx->update->callbackData());
        $screen = $route === null ? null : $this->screens->get($route['screen']);
        if ($route === null || $screen === null || !$screen->allows($admin->seat)) {
            $this->show($admin, $this->screens->home()->show($admin, []), fresh: false);

            return;
        }

        try {
            $view = $route['action'] === Nav::SHOW ? $screen->show($admin, $route['args']) : $screen->act($admin, $route['action'], $route['args']);
        } catch (ApiRefusal $refusal) {
            $admin->alert($refusal->lines());

            return;
        }
        $this->show($admin, $view, fresh: false);
    }

    /**
     * A message at a question: the screen that asked takes it; refused, the question stays, the refusal over it. The
     * message is taken away once read, whatever came of it.
     */
    private function answered(Admin $admin): void
    {
        $ctx = $admin->ctx;
        try {
            $kept = $ctx->session->get('adm_prompt');
            $screen = is_array($kept) && is_string($kept['screen'] ?? null) ? $this->screens->get($kept['screen']) : null;
            if (!is_array($kept) || $screen === null || !$screen->allows($admin->seat)) {
                $ctx->session->clear();
                $this->show($admin, $this->screens->home()->show($admin, []), fresh: true);

                return;
            }
            $prompt = Prompt::kept($kept);
            $answer = new Answer($ctx->update, $ctx->api);
            if (!$prompt->file && $answer->text() === '') {
                $this->show($admin, $admin->ask($prompt, again: true)->notices(['⚠️ پاسخ را به صورت متن بفرستید.']), fresh: true);

                return;
            }

            $ctx->session->clear();
            try {
                $view = $screen->input($admin, $prompt->action, array_map(strval(...), $prompt->args), $answer);
            } catch (ApiRefusal $refusal) {
                // The question again, as it was asked, the refusal over it.
                $this->show($admin, $admin->ask($prompt, again: true)->notices(['⚠️ ' . $refusal->lines()]), fresh: true);

                return;
            }
            $this->show($admin, $view, fresh: true);
        } finally {
            // Best effort: a message past what Telegram lets a bot delete stays.
            $ctx->deleteIncoming();
        }
    }

    /**
     * Show `$view` in the window: in place for a tap, else as a new message — the last window taken away.
     */
    private function show(Admin $admin, View $view, bool $fresh): void
    {
        $ctx = $admin->ctx;
        $view->notices($admin->takeNotices());
        $options = ['reply_markup' => $view->keyboard(), 'link_preview_options' => ['is_disabled' => true]];
        $photo = $view->photo();

        if (!$fresh && $ctx->update->isCallback()) {
            // A screen that turns from text to a picture or back takes a new message: the window is that one.
            $this->keep($ctx, $photo !== null
                ? $ctx->editPhoto($photo->bytes, $photo->filename, ['caption' => $view->text(), 'reply_markup' => $view->keyboard()])
                : $ctx->edit($view->text(), $options));

            return;
        }

        $previous = $this->window($ctx);
        $sent = $photo !== null
            ? $ctx->api->sendPhoto($ctx->chatId(), $photo->bytes, $photo->filename, ['caption' => $view->text(), 'reply_markup' => $view->keyboard()])
            : $ctx->reply($view->text(), $options);
        if ($previous !== null) {
            // Best effort: one gone already, or past the two days Telegram lets a bot delete in, stays above.
            $ctx->deleteMessage($previous);
        }
        $this->keep($ctx, is_int($sent['message_id'] ?? null) ? $sent['message_id'] : null);
    }

    private function window(Context $ctx): ?int
    {
        $fact = $ctx->session->get(Admin::FACT, []);

        return is_array($fact) && is_int($fact[self::MESSAGE] ?? null) ? $fact[self::MESSAGE] : null;
    }

    private function keep(Context $ctx, ?int $messageId): void
    {
        $fact = $ctx->session->get(Admin::FACT, []);
        $fact = is_array($fact) ? $fact : [];
        $fact[self::MESSAGE] = $messageId;
        $ctx->session->put(Admin::FACT, $fact);
    }
}
