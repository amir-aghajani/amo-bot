<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Session;

use App\Modules\Telegram\Models\TelegramSession;

/**
 * One chat's conversation: the step of a flow it is at (`state`, e.g. "topup.amount", "receipt:12") with that step's
 * scratch, and the chat's own facts, which outlive every flow (data keys starting with "_": whether a reply keyboard is
 * shown, a recent membership check). Handlers change it; the dispatcher saves it after the update.
 */
final class ChatSession
{
    /** Whether a reply keyboard is on the client's screen: Telegram keeps one there until a message takes it away. */
    private const REPLY_KEYBOARD = '_keyboard';

    private bool $dirty = false;

    public function __construct(private readonly TelegramSession $model) {}

    /** The step the chat is at; null outside any flow. */
    public function state(): ?string
    {
        return $this->model->state;
    }

    /** Whether the chat is at `$prefix` or one of its steps ("agency" holds for "agency.note"). */
    public function inState(string $prefix): bool
    {
        $state = $this->state();

        return $state !== null && ($state === $prefix || str_starts_with($state, $prefix . '.'));
    }

    /**
     * Go to a step with its scratch: the previous step's scratch goes, the chat's own facts stay.
     *
     * @param array<string, mixed> $scratch
     */
    public function enter(string $state, array $scratch = []): void
    {
        $this->model->state = $state;
        $this->model->data = $scratch + $this->facts();
        $this->dirty = true;
    }

    /** A value of the step's scratch, or one of the chat's own facts. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->model->data[$key] ?? $default;
    }

    /** Keep a value: in the step's scratch, or as a chat's fact when `$key` starts with "_". */
    public function put(string $key, mixed $value): void
    {
        // In place, keeping the bag's order: putting a value again as it was leaves the row unchanged, so nothing is written.
        $data = $this->model->data ?? [];
        $data[$key] = $value;
        $this->model->data = $data;
        $this->dirty = true;
    }

    /** Leave the flow: its state and scratch go, the chat's own facts stay. */
    public function clear(): void
    {
        $this->model->state = null;
        $this->model->data = $this->facts();
        $this->dirty = true;
    }

    /**
     * Leave the flow at a last step that must run once (a broadcast sent, a link changed): one conditional update of
     * the stored state, so of two taps at the same moment only one gets true.
     */
    public function claim(string $state): bool
    {
        $claimed = $this->model->newQuery()->whereKey($this->model->getKey())->where('state', $state)->update(['state' => null]) === 1;
        $this->clear();
        if ($claimed) {
            // The row holds no state now, so a step entered again after all (a start refused at the last moment) is written.
            $this->model->syncOriginalAttribute('state');
        }

        return $claimed;
    }

    public function showsReplyKeyboard(): bool
    {
        return $this->get(self::REPLY_KEYBOARD) === true;
    }

    /** What the message just sent did to the reply keyboard: put one on the client's screen, or took it away. */
    public function markReplyKeyboard(bool $shown): void
    {
        $this->put(self::REPLY_KEYBOARD, $shown);
    }

    /** Write what the update changed (Eloquent skips values that are as they were). */
    public function save(): void
    {
        if ($this->dirty) {
            $this->model->save();
            $this->dirty = false;
        }
    }

    /** @return array<string, mixed> The chat's own facts in the data bag: what outlives a flow. */
    private function facts(): array
    {
        return array_filter($this->model->data ?? [], static fn(string $key): bool => str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }
}
