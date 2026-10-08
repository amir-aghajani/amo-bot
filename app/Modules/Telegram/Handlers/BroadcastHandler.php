<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastControl;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Services\CustomerGroups;
use App\Support\Persian;

/**
 * /broadcast — a bot admin's «ارسال همگانی» (routes/bot.php serves it to the bot's admins only). The admin sends the
 * message (any kind: it is copied as it is, so photos and formatting survive; a channel post forwarded here can go on as
 * a forward), and the bot answers with a draft card:
 *   broadcast:mode            copy (from the bot, the admin's link buttons under it) or forward (from its first sender)
 *   broadcast:aud[:key[:id]]  the audience: everyone, buyers, non-buyers, the inactive, a customer group, agents, one
 *                             server's customers — each with its size
 *   broadcast:pin             pin each message in its chat
 *   broadcast:btn[:clear]     the link buttons: typed a row a line (state broadcast.buttons), or taken off
 *   broadcast:send / drop     start it (claimed once) / drop the draft
 * Sent, the card becomes the run's live progress message, its buttons the run's controls: broadcast:pause|resume|cancel:<id>
 * and, once a pinned one is over, broadcast:unpin:<id>. Delivery starts right here for the first few customers and the
 * scheduler carries on.
 */
final class BroadcastHandler implements Handler
{
    public const COMMAND = 'broadcast';
    public const STATE = 'broadcast';
    public const CALLBACK = 'broadcast:';

    /** A finished pinned run's button: its pins taken off. */
    public const UNPIN = 'unpin';

    private const COMPOSE = 'broadcast.compose';
    private const DRAFT = 'broadcast.draft';
    private const BUTTONS = 'broadcast.buttons';

    /** The draft, as the session keeps it. */
    private const KEY = 'broadcast';

    /** Customers handled before the bot answers the admin; the scheduler takes it from there. */
    private const INLINE_LIMIT = 50;

    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly CustomerGroups $groups,
        private readonly BotTexts $texts,
        private readonly Buttons $buttons,
    ) {}

    /** A run's control on its progress message: `broadcast:<pause|resume|cancel|unpin>:<id>`. */
    public static function controlCallback(string $action, int $broadcastId): string
    {
        return CallbackData::build(self::CALLBACK, $action, $broadcastId);
    }

    public function handle(Context $ctx): void
    {
        $update = $ctx->update;
        if ($update->isCommand()) {
            $this->begin($ctx);

            return;
        }

        $args = $update->callbackArgs(self::CALLBACK);
        if ($args === null) {
            $ctx->session->inState(self::BUTTONS) ? $this->typedButtons($ctx) : $this->capture($ctx);

            return;
        }

        $action = $args[0] ?? '';
        $control = BroadcastControl::tryFrom($action);
        if ($control !== null || $action === self::UNPIN) {
            $this->control($ctx, $control, (int) ($args[1] ?? 0));

            return;
        }
        if ($action === 'drop') {
            $this->drop($ctx);

            return;
        }

        /** @var array<string, \Closure(array<string, mixed>): void> $steps The draft card's buttons */
        $steps = [
            'mode' => fn(array $draft) => $this->redraw($ctx, ['mode' => $draft['mode'] === BroadcastMode::Forward->value ? BroadcastMode::Copy->value : BroadcastMode::Forward->value] + $draft),
            'pin' => fn(array $draft) => $this->redraw($ctx, ['pin' => !$draft['pin']] + $draft),
            'aud' => fn(array $draft) => $this->audience($ctx, $draft, $args[1] ?? null, isset($args[2]) ? (int) $args[2] : null),
            'btn' => fn(array $draft) => ($args[1] ?? '') === 'clear' ? $this->redraw($ctx, ['buttons' => []] + $draft) : $this->askButtons($ctx, $draft),
            'card' => fn(array $draft) => $this->redraw($ctx, $draft),
            'send' => fn(array $draft) => $this->send($ctx, $draft),
        ];
        if (isset($steps[$action])) {
            $this->withDraft($ctx, $steps[$action]);
        }
    }

    private function begin(Context $ctx): void
    {
        $ctx->session->enter(self::COMPOSE);
        $ctx->reply(Messages::BROADCAST_ASK, ['reply_markup' => InlineKeyboard::make()->row($this->dropButton())->build()]);
    }

    /**
     * The admin sent the message to broadcast — or another in place of the one on the draft card, which keeps its
     * settings: the card comes again, under it.
     */
    private function capture(Context $ctx): void
    {
        $messageId = $ctx->update->messageId();
        $message = $ctx->update->message() ?? [];
        if ($messageId === null || $message === []) {
            $ctx->reply(Messages::BROADCAST_ASK, ['reply_markup' => InlineKeyboard::make()->row($this->dropButton())->build()]);

            return;
        }

        $text = $message['text'] ?? $message['caption'] ?? null;
        $previous = $this->draft($ctx);

        $this->freshCard($ctx, [
            'message_id' => $messageId,
            'content' => $ctx->update->contentKind(),
            'excerpt' => is_string($text) ? trim($text) : null,
            // A channel post forwarded here goes on as a forward unless the admin says otherwise.
            'mode' => $previous['mode'] ?? (isset($message['forward_origin']) ? BroadcastMode::Forward->value : BroadcastMode::Copy->value),
            'audience' => $previous['audience'] ?? Audience::ALL,
            'audience_id' => $previous['audience_id'] ?? null,
            'pin' => $previous['pin'] ?? false,
            'buttons' => $previous['buttons'] ?? [],
            'card' => $previous['card'] ?? null,
        ]);
    }

    /** State broadcast.buttons: the admin's link buttons, typed — kept, or the line that is not right said. */
    private function typedButtons(Context $ctx): void
    {
        $draft = $this->draft($ctx);
        if ($draft === null) {
            $ctx->session->clear();
            $ctx->reply(Messages::BROADCAST_EXPIRED);

            return;
        }

        $typed = trim((string) $ctx->update->text());
        if ($typed === '') {
            $ctx->reply(Messages::BROADCAST_BUTTONS_ASK);

            return;
        }

        try {
            $buttons = BroadcastService::parseButtons($typed);
        } catch (ValidationException $e) {
            // The instructions above keep their «بازگشت»; the admin types the lines again.
            $ctx->reply($e->getMessage());

            return;
        }

        $this->freshCard($ctx, ['buttons' => $buttons] + $draft);
    }

    /**
     * The draft card under everything in the chat — the old one taken away — and the draft waiting on it.
     *
     * @param array<string, mixed> $draft
     */
    private function freshCard(Context $ctx, array $draft): void
    {
        if (is_int($draft['card'] ?? null)) {
            $ctx->deleteMessage($draft['card']);
        }

        [$text, $keyboard] = $this->card($draft);
        $sent = $ctx->reply($text, ['reply_markup' => $keyboard]);
        $ctx->session->enter(self::DRAFT, [self::KEY => ['card' => isset($sent['message_id']) ? (int) $sent['message_id'] : null] + $draft]);
    }

    /**
     * The draft card drawn again in place (a setting changed), the draft waiting on it.
     *
     * @param array<string, mixed> $draft
     */
    private function redraw(Context $ctx, array $draft): void
    {
        $ctx->session->enter(self::DRAFT, [self::KEY => $draft]);
        [$text, $keyboard] = $this->card($draft);
        $ctx->edit($text, ['reply_markup' => $keyboard]);
    }

    /**
     * broadcast:aud — the picker (each audience with its size); …:group / …:server — the groups or servers to pick from;
     * any other — that audience, back on the card.
     *
     * @param array<string, mixed> $draft
     */
    private function audience(Context $ctx, array $draft, ?string $key, ?int $id): void
    {
        if ($key === null) {
            // An agent's bot has no agents of its own to write to.
            $keys = [Audience::ALL, Audience::BUYERS, Audience::NON_BUYERS, Audience::INACTIVE, ...(CurrentBot::isMain() ? [Audience::AGENTS] : [])];
            $choices = array_map(fn(string $simple): array => InlineKeyboard::callback(self::option(Audience::LABELS[$simple], Audience::of($simple)?->users()->count() ?? 0), $this->audienceCallback($simple)), $keys);
            $choices[] = InlineKeyboard::callback('👥 ' . Audience::LABELS[Audience::GROUP] . '…', $this->audienceCallback(Audience::GROUP));
            $choices[] = InlineKeyboard::callback('🖥 ' . Audience::LABELS[Audience::SERVER] . '…', $this->audienceCallback(Audience::SERVER));
            $ctx->edit(Messages::BROADCAST_AUDIENCE_ASK, ['reply_markup' => InlineKeyboard::make()->grid($choices, 1)->row($this->buttons->back(self::callback('card')))->build()]);

            return;
        }

        if (in_array($key, Audience::WITH_ID, true) && $id === null) {
            $this->pickOne($ctx, $key);

            return;
        }

        $audience = Audience::of($key, $id);
        if ($audience === null) {
            $ctx->answer(Messages::BROADCAST_AUDIENCE_GONE, alert: true);

            return;
        }

        $this->redraw($ctx, ['audience' => $audience->key, 'audience_id' => $audience->id] + $draft);
    }

    /** The admin's groups, or the servers this bot's customers are on, each with how many customers it would reach. */
    private function pickOne(Context $ctx, string $key): void
    {
        $choices = $key === Audience::GROUP
            ? $this->groups->ordered()->map(static fn(CustomerGroup $group): int => $group->id)->all()
            : Audience::servers()->modelKeys();
        $back = InlineKeyboard::make()->row($this->buttons->back(self::callback('aud')));
        if ($choices === []) {
            $ctx->edit($key === Audience::GROUP ? Messages::BROADCAST_NO_GROUPS : Messages::BROADCAST_NO_SERVERS, ['reply_markup' => $back->build()]);

            return;
        }

        $buttons = [];
        foreach ($choices as $id) {
            $audience = Audience::of($key, $id);
            if ($audience !== null) {
                $buttons[] = InlineKeyboard::callback(self::option($audience->name(), $audience->users()->count()), $this->audienceCallback($key, $id));
            }
        }
        $ctx->edit($key === Audience::GROUP ? Messages::BROADCAST_GROUP_ASK : Messages::BROADCAST_SERVER_ASK, [
            'reply_markup' => InlineKeyboard::make()->grid($buttons, 1)->row($this->buttons->back(self::callback('aud')))->build(),
        ]);
    }

    /** @param array<string, mixed> $draft */
    private function askButtons(Context $ctx, array $draft): void
    {
        $ctx->session->enter(self::BUTTONS, [self::KEY => $draft]);
        $keyboard = InlineKeyboard::make();
        if ($draft['buttons'] !== []) {
            $keyboard->row(InlineKeyboard::callback(Messages::BROADCAST_BUTTONS_CLEAR, self::callback('btn', 'clear'), 'danger'));
        }
        $ctx->edit(Messages::BROADCAST_BUTTONS_ASK, ['reply_markup' => $keyboard->row($this->buttons->back(self::callback('card')))->build()]);
    }

    /**
     * «ارسال»: the draft becomes a run — once, though the button be pressed twice — and the card its progress message;
     * the first customers get it now.
     *
     * @param array<string, mixed> $draft
     */
    private function send(Context $ctx, array $draft): void
    {
        $audience = Audience::of((string) $draft['audience'], $draft['audience_id']);
        if ($audience === null || $audience->users()->count() === 0) {
            $ctx->answer($audience === null ? Messages::BROADCAST_AUDIENCE_GONE : Messages::BROADCAST_EMPTY_AUDIENCE, alert: true);

            return;
        }
        if (!$ctx->session->claim(self::DRAFT)) {
            return;
        }

        /** @var list<list<array{text: string, url: string}>> $buttons parseButtons()' rows, as the session kept them */
        $buttons = is_array($draft['buttons'] ?? null) ? $draft['buttons'] : [];

        try {
            $broadcast = $this->broadcasts->start($ctx->user, [
                'message_id' => (int) $draft['message_id'],
                'content' => is_string($draft['content'] ?? null) ? $draft['content'] : null,
                'excerpt' => is_string($draft['excerpt'] ?? null) ? $draft['excerpt'] : null,
                'mode' => BroadcastMode::from((string) $draft['mode']),
                'audience' => $audience->key,
                'audience_id' => $audience->id,
                'pin' => (bool) $draft['pin'],
                'buttons' => $buttons,
            ], $ctx->update->messageId());
        } catch (ValidationException $e) {
            // The audience went between the look and the start: the draft waits for another.
            $ctx->session->enter(self::DRAFT, [self::KEY => $draft]);
            $ctx->answer($e->getMessage(), alert: true);

            return;
        }

        // Answered now: the first batch takes seconds, and the button would spin through them.
        $ctx->answer();
        $this->broadcasts->showProgress($broadcast);
        $this->broadcasts->process($broadcast, self::INLINE_LIMIT);
    }

    private function drop(Context $ctx): void
    {
        $ctx->session->clear();
        $ctx->edit(Messages::BROADCAST_CANCELLED);
    }

    /** A run's button on its progress message: pause, resume, cancel it — or (`$control` null) take a finished one's pins off. */
    private function control(Context $ctx, ?BroadcastControl $control, int $id): void
    {
        $broadcast = Broadcast::query()->find($id);
        if ($broadcast === null) {
            $ctx->answer(Messages::BROADCAST_UNCHANGED, alert: true);

            return;
        }
        if ($control === null) {
            $this->unpin($ctx, $broadcast);

            return;
        }

        if (!$this->broadcasts->control($broadcast, $control)) {
            $ctx->answer(Messages::BROADCAST_UNCHANGED, alert: true);
            $this->broadcasts->showProgress($broadcast);

            return;
        }

        $ctx->answer(match ($control) {
            BroadcastControl::Pause => Messages::BROADCAST_PAUSED,
            BroadcastControl::Resume => Messages::BROADCAST_RESUMED,
            BroadcastControl::Cancel => Messages::BROADCAST_STOPPED,
        });
        if ($control === BroadcastControl::Resume) {
            $this->broadcasts->process($broadcast, self::INLINE_LIMIT);
        }
    }

    /** «لغو پین»: a run that takes the pins off, its own progress message under everything. */
    private function unpin(Context $ctx, Broadcast $broadcast): void
    {
        try {
            $run = $this->broadcasts->startUnpin($broadcast, $ctx->user, null);
        } catch (ValidationException) {
            $ctx->answer(Messages::BROADCAST_UNCHANGED, alert: true);
            $this->broadcasts->showProgress($broadcast);

            return;
        }

        $sent = $ctx->reply(Messages::BROADCAST_UNPINNING);
        $run->forceFill(['progress_message_id' => isset($sent['message_id']) ? (int) $sent['message_id'] : null])->save();
        $ctx->answer(Messages::BROADCAST_UNPINNING);
        $this->broadcasts->showProgress($broadcast);
        $this->broadcasts->showProgress($run);
        $this->broadcasts->process($run, self::INLINE_LIMIT);
    }

    /**
     * Run a draft step with the draft waiting for it, or say it is gone (a menu tap, a draft sent or dropped).
     *
     * @param \Closure(array<string, mixed>): void $step
     */
    private function withDraft(Context $ctx, \Closure $step): void
    {
        $draft = $this->draft($ctx);
        if ($draft === null || !($ctx->session->inState(self::DRAFT) || $ctx->session->inState(self::BUTTONS))) {
            $ctx->edit(Messages::BROADCAST_EXPIRED);

            return;
        }

        $step($draft);
    }

    /** @return array<string, mixed>|null The draft being put together, as the session keeps it. */
    private function draft(Context $ctx): ?array
    {
        $draft = $ctx->session->get(self::KEY);

        return is_array($draft) && is_int($draft['message_id'] ?? null) ? $draft : null;
    }

    /**
     * The draft card: what goes, how, to whom (and how many), pinned or not, with which buttons — and its settings as
     * buttons; «ارسال» only while the audience reaches someone.
     *
     * @param array<string, mixed> $draft
     * @return array{string, array<string, mixed>}
     */
    private function card(array $draft): array
    {
        $audience = Audience::of((string) $draft['audience'], $draft['audience_id']);
        ['total' => $total, 'blocked' => $blocked] = $audience?->size() ?? ['total' => 0, 'blocked' => 0];
        $forward = $draft['mode'] === BroadcastMode::Forward->value;

        $content = Messages::BROADCAST_CONTENT[(string) $draft['content']] ?? Messages::BROADCAST_CONTENT_OTHER;
        if (is_string($draft['excerpt']) && $draft['excerpt'] !== '') {
            $content .= ' — «' . htmlspecialchars(mb_strimwidth($draft['excerpt'], 0, 60, '…')) . '»';
        }

        $text = Messages::fill(Messages::BROADCAST_DRAFT, [
            'content' => $content,
            'mode' => $forward ? Messages::BROADCAST_MODE_FORWARD : Messages::BROADCAST_MODE_COPY,
            'audience' => htmlspecialchars($audience?->label() ?? Audience::labelOf((string) $draft['audience'], $draft['audience_id'])),
            'count' => Persian::number($total),
            'blocked' => $blocked > 0 ? Messages::fill(Messages::BROADCAST_DRAFT_BLOCKED, ['count' => Persian::number($blocked)]) : '',
            'pin' => $draft['pin'] ? Messages::BROADCAST_ON : Messages::BROADCAST_OFF,
            'buttons' => match (true) {
                $forward => Messages::BROADCAST_FORWARD_NO_BUTTONS,
                $draft['buttons'] === [] => Messages::BROADCAST_NO_BUTTONS,
                default => Messages::fill(Messages::BROADCAST_BUTTON_COUNT, ['count' => Persian::number(array_sum(array_map('count', $draft['buttons'])))]),
            },
        ]);

        $keyboard = InlineKeyboard::make()->row(
            InlineKeyboard::callback(Messages::BROADCAST_BTN_AUDIENCE, self::callback('aud')),
            InlineKeyboard::callback(Messages::fill(Messages::BROADCAST_BTN_MODE, ['mode' => $forward ? Messages::BROADCAST_BTN_FORWARD : Messages::BROADCAST_BTN_COPY]), self::callback('mode')),
        );
        $pin = InlineKeyboard::callback(Messages::fill(Messages::BROADCAST_BTN_PIN, ['state' => $draft['pin'] ? Messages::BROADCAST_ON : Messages::BROADCAST_OFF]), self::callback('pin'), $draft['pin'] ? 'success' : null);
        // A forward takes no buttons of its own.
        $forward ? $keyboard->row($pin) : $keyboard->row(InlineKeyboard::callback(Messages::BROADCAST_BTN_BUTTONS, self::callback('btn')), $pin);
        $keyboard->row(...array_filter([
            $this->dropButton(),
            $total > 0 ? InlineKeyboard::callback(Messages::fill(Messages::BROADCAST_SEND, ['count' => Persian::number($total)]), self::callback('send'), 'success') : null,
        ]));

        return [$text, $keyboard->build()];
    }

    /** @return array<string, mixed> «❌ انصراف»: the draft dropped. */
    private function dropButton(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::Cancel), self::callback('drop'), 'danger');
    }

    private function audienceCallback(string $key, ?int $id = null): string
    {
        return $id === null ? self::callback('aud', $key) : self::callback('aud', $key, $id);
    }

    private static function callback(int|string ...$args): string
    {
        return CallbackData::build(self::CALLBACK, ...$args);
    }

    /** «خریداران (۱۲۰)». */
    private static function option(string $label, int $count): string
    {
        return Messages::fill(Messages::BROADCAST_AUDIENCE_OPTION, ['label' => $label, 'count' => Persian::number($count)]);
    }
}
