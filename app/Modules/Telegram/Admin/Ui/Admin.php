<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiFile;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Context;

/**
 * One update of the shop's management in its bot's private chat, as its screens see it: who manages (`seat`), the
 * panels' API as they use it (`api`), what each screen keeps between taps (state(): a page, a filter, a search, a
 * draft — the chat's own facts, so a customer flow in between loses none of it), and the ways a screen answers: another
 * screen (open()), a question (ask()), a confirmation (confirm()), a word (notice()), a file (send()).
 */
final class Admin
{
    /** The chat state the management's questions wait in (Prompt). */
    public const INPUT = 'adm.input';

    /** The step of it a question whose answer may be a menu button's label waits at (Prompt::$anyText): it captures. */
    public const INPUT_ANY = self::INPUT . '.any';

    /** The chat's fact the management keeps its screens' state, its message and the owner's shop under. */
    public const FACT = '_adm';

    /**
     * @var list<string> Words for the next screen shown: an action done after a typed answer (no popup to say it in).
     */
    private array $notices = [];

    /**
     * @param AdminSeat $seat Who manages — another shop's seat once the owner picks one (pickShop()); screens read it
     * @param PanelBridge $api The panels' API as the seat uses it; screens read it
     */
    public function __construct(
        public readonly Context $ctx,
        public AdminSeat $seat,
        public PanelBridge $api,
        private readonly Screens $screens,
    ) {}

    /**
     * What `$screen` keeps between taps (its page, filter, search, a draft).
     *
     * @return array<string, mixed>
     */
    public function state(string $screen): array
    {
        $screens = $this->fact()['screens'] ?? [];

        return is_array($screens[$screen] ?? null) ? $screens[$screen] : [];
    }

    /**
     * Keep `$values` in `$screen`'s state, beside what it holds.
     *
     * @param array<string, mixed> $values
     */
    public function remember(string $screen, array $values): void
    {
        $fact = $this->fact();
        $fact['screens'][$screen] = array_replace($this->state($screen), $values);
        $this->ctx->session->put(self::FACT, $fact);
    }

    /** Drop `$screen`'s state: a new search, a draft ended. */
    public function forget(string $screen): void
    {
        $fact = $this->fact();
        unset($fact['screens'][$screen]);
        $this->ctx->session->put(self::FACT, $fact);
    }

    /** Whether `$screen` is built and the seat may open it: a button to it is worth showing. */
    public function opens(string $screen): bool
    {
        return $this->screens->get($screen)?->allows($this->seat) === true;
    }

    /**
     * Another screen's view: `$screen`'s show() — or `$action` — with `$args` (after a row deleted, its list).
     *
     * @param list<int|string> $args
     * @throws ApiRefusal
     */
    public function open(string $screen, string $action = Nav::SHOW, array $args = []): View
    {
        $target = $this->screens->get($screen);
        if ($target === null || !$target->allows($this->seat)) {
            return $this->screens->home()->show($this, []);
        }
        $args = array_map(strval(...), $args);

        return $action === Nav::SHOW ? $target->show($this, $args) : $target->act($this, $action, $args);
    }

    /**
     * Ask `$prompt`: the chat waits for the answer, which the screen's input() takes — `$again` after an answer refused
     * (the refusal said over it).
     */
    public function ask(Prompt $prompt, bool $again = false): View
    {
        $this->ctx->session->enter($prompt->anyText ? self::INPUT_ANY : self::INPUT, ['adm_prompt' => $prompt->keep()]);
        $view = View::make('✍️ ' . $prompt->question);
        if ($prompt->hint !== '') {
            $view->line($prompt->hint);
        }
        $view->note(match (true) {
            $again => 'دوباره بفرستید، یا لغو کنید.',
            $prompt->file => 'پاسخ را همین‌جا بفرستید.',
            default => 'پاسخ را همین‌جا بنویسید و بفرستید.',
        });
        foreach (array_chunk($prompt->choices, 2) as $row) {
            $view->row(...$row);
        }

        return $view->row($prompt->cancel ?? Button::to('✖️ لغو', $prompt->screen, Nav::SHOW, ...$prompt->args));
    }

    /**
     * A question before a change that is not undone (a delete, a refund): `$question`, what it does (`$detail`, HTML),
     * «✔️ …» doing it and «✖️ انصراف» not.
     */
    public function confirm(string $question, Button $yes, Button $no, string $detail = ''): View
    {
        $view = View::make('❓ ' . $question);
        if ($detail !== '') {
            $view->line($detail);
        }

        return $view->row($yes, $no);
    }

    /**
     * Words about what was just done: a popup's toast for a tap, else a line atop the next screen (after a typed answer).
     */
    public function notice(string $text): void
    {
        if ($this->ctx->update->isCallback() && !$this->ctx->answered()) {
            $this->ctx->answer($text);

            return;
        }
        $this->notices[] = $text;
    }

    /** A popup to read and dismiss: a refusal, a warning. */
    public function alert(string $text): void
    {
        if ($this->ctx->update->isCallback() && !$this->ctx->answered()) {
            $this->ctx->answer($text, alert: true);

            return;
        }
        $this->notices[] = '⚠️ ' . $text;
    }

    /**
     * @return list<string> The words the next screen shows first, taken once.
     */
    public function takeNotices(): array
    {
        $notices = $this->notices;
        $this->notices = [];

        return $notices;
    }

    /**
     * A file as its own message under the screen (a receipt, a backup, a list), with `$caption` (HTML). Telegram
     * refusing it or out of reach is said, never thrown: what was done before it (codes made, a backup taken) stands,
     * and the screen after it still shows. Returns whether it went: a screen says «sent» only then.
     */
    public function send(ApiFile $file, string $caption = ''): bool
    {
        $options = $caption === '' ? [] : ['caption' => $caption];
        try {
            if ($file->isImage()) {
                $this->ctx->api->sendPhoto($this->ctx->chatId(), $file->bytes, $file->filename, $options);
            } else {
                $this->ctx->api->sendDocument($this->ctx->chatId(), $file->bytes, $file->filename, $options);
            }

            return true;
        } catch (TelegramApiException | TelegramUnreachableException) {
            $this->alert('تلگرام فایل «' . $file->filename . '» را نفرستاد؛ از پنل بگیرید یا کمی بعد دوباره بخواهید.');

            return false;
        }
    }

    /** The owner works shop `$shop` from now on («🏪 فروشگاه»): this update's next screens, and the chat's after. */
    public function pickShop(int $shop): void
    {
        $bot = Bot::query()->find($shop);
        if ($bot === null || !$this->seat->isOwner()) {
            return;
        }
        $fact = $this->fact();
        $fact['shop'] = $bot->id;
        // Another shop's screens keep nothing of this one's (a page, a filter, a draft).
        $fact['screens'] = [];
        $this->ctx->session->put(self::FACT, $fact);
        $this->seat = $this->seat->in($bot);
        $this->api = $this->api->for($this->seat);
    }

    /**
     * @return array<string, mixed>
     */
    private function fact(): array
    {
        $fact = $this->ctx->session->get(self::FACT, []);

        return is_array($fact) ? $fact : [];
    }
}
