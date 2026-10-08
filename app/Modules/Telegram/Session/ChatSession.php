<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Session;

use App\Modules\Telegram\Models\TelegramSession;

/**
 * One chat's conversation: the step of a flow it is in (`state` — "topup.amount", "receipt:12": a handler's prefix and
 * where it is) with that step's scratch, and the chat's own facts, which outlive every flow (keys starting with "_" in
 * the data bag: whether a reply keyboard is on the client's screen, a membership confirmed lately). Handlers change it;
 * the dispatcher saves it once the update is served.
 */
final class ChatSession
{
    /** Whether a reply keyboard is on the client's screen: Telegram keeps one there until a message takes it away. */
    private const REPLY_KEYBOARD = '_keyboard';

    private bool $dirty = false;

    public function __construct(private readonly TelegramSession $model) {}

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
     * Go to a step of a flow with what it needs to keep (`$scratch`): the step before and its scratch are left behind,
     * the chat's own facts stay.
     *
     * @param array<string, mixed> $scratch
     */
    public function enter(string $state, array $scratch = []): void
    {
        $this->model->state = $state;
        $this->model->data = $scratch + $this->facts();
        $this->dirty = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->model->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        // In place, the bag's order kept: a value put again as it was leaves the row as it was, and nothing is written.
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
     * Leave the flow at a last step that must run once (a broadcast sent, a link changed), as clear() does: true for
     * the one update that moved the stored state off `$state` — of two taps on the same button in the same moment, the
     * other gets false and does nothing.
     */
    public function claim(string $state): bool
    {
        $claimed = $this->model->newQuery()->whereKey($this->model->getKey())->where('state', $state)->update(['state' => null]) === 1;
        $this->clear();
        if ($claimed) {
            // The row holds no state now: a step entered again after all (a start refused at the last moment) is a change to write.
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

    /** Write what the update changed — Eloquent writes nothing when the values are as they were. */
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
