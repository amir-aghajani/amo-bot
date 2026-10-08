<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Telegram\Models\ReportChat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The current bot's report group as the shop knows it right now (`report_chats`, a row per bot — every bot reports to
 * a group of its own): which group is connected, what keeps reports from it, until when sending waits, the one-time
 * code that hands the bot a group, and the last group offered with that code that could not become it. Every read asks
 * the table — the panel, the bot's poller and the cron change it while the others run, so no process decides on a copy
 * it took earlier — and every change that depends on what is there is one conditional UPDATE. Nothing here talks to
 * Telegram; which topics the admin wants is ReportSettings'.
 */
final class ReportGroupState
{
    /** How long a connect link works. */
    public const CODE_MINUTES = 60;

    /** The connected group's chat id (-100…), or null when none is connected. */
    public function chatId(): ?int
    {
        return $this->row()?->chat_id;
    }

    public function title(): ?string
    {
        return $this->row()?->title;
    }

    public function connectedAt(): ?Carbon
    {
        return $this->row()?->connected_at;
    }

    /** What keeps reports from reaching the group (the bot was removed, lost its rights…); null when nothing does. */
    public function problem(): ?GroupProblem
    {
        return $this->row()?->problem;
    }

    /** Until when sending waits — a flood limit, an unreachable Telegram, a problem with the group; null once it is over. */
    public function pausedUntil(): ?Carbon
    {
        $until = $this->row()?->paused_until;

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /** The connected group's chat id while reports may go to it now; null while none is connected or sending waits. */
    public function sendingTo(): ?int
    {
        $row = $this->row();
        $paused = $row?->paused_until !== null && $row->paused_until->isFuture();

        return $paused ? null : $row?->chat_id;
    }

    /** A group was connected: it is the report group from now on, with nothing wrong, no wait and no code outstanding. */
    public function connected(int $chatId, string $title): void
    {
        if (!ReportChat::query()->exists()) {
            // Of two processes making the bot's row at once, one makes it and the other finds it.
            ReportChat::query()->createOrFirst();
        }

        ReportChat::query()->update([
            'chat_id' => $chatId,
            'title' => $title,
            'connected_at' => now(),
            'problem' => null,
            'paused_until' => null,
            'code' => null,
            'code_expires_at' => null,
            'attempt_title' => null,
            'attempt_problem' => null,
            'attempted_at' => null,
        ]);
    }

    /** No report group any more, nor a code to connect one (the topic switches are settings, and stay). */
    public function forget(): void
    {
        ReportChat::query()->delete();
    }

    public function retitle(string $title): void
    {
        ReportChat::query()->update(['title' => $title]);
    }

    /** What is wrong with the group now (null: nothing) — written only when it changes, so a busy sender does not rewrite the row. */
    public function setProblem(?GroupProblem $problem): void
    {
        $changed = $problem === null
            ? ReportChat::query()->whereNotNull('problem')
            : ReportChat::query()->where(static fn(Builder $q) => $q->whereNull('problem')->orWhere('problem', '!=', $problem->value));

        $changed->update(['problem' => $problem?->value]);
    }

    /** Hold sending for this long; a longer hold already in place stands. */
    public function pause(int $seconds): void
    {
        $until = now()->addSeconds(max(1, $seconds));

        ReportChat::query()
            ->where(static fn(Builder $q) => $q->whereNull('paused_until')->orWhere('paused_until', '<', $until))
            ->update(['paused_until' => $until]);
    }

    public function resume(): void
    {
        ReportChat::query()->whereNotNull('paused_until')->update(['paused_until' => null]);
    }

    /**
     * A new connect code, replacing any other: random, good for CODE_MINUTES, kept encrypted (the screen shows its link
     * again until it is used or runs out). The refusal of an earlier attempt goes with the old code.
     *
     * @return array{code: string, expires_at: Carbon}
     */
    public function issueCode(): array
    {
        $code = bin2hex(random_bytes(16));
        $expiresAt = now()->addMinutes(self::CODE_MINUTES);

        ReportChat::query()->updateOrCreate([], ['code' => $code, 'code_expires_at' => $expiresAt, 'attempt_title' => null, 'attempt_problem' => null, 'attempted_at' => null]);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * The code outstanding, while it still works.
     *
     * @return array{code: string, expires_at: Carbon}|null
     */
    public function code(): ?array
    {
        return self::outstanding($this->row());
    }

    /** Whether `$code` is the code outstanding (read-only: checking a group's fitness does not use it up). */
    public function matches(string $code): bool
    {
        return self::same(self::outstanding($this->row()), $code);
    }

    /**
     * Use the code up — a compare-and-swap on the very bytes read, so of two messages carrying it (two taps, two webhook
     * requests) exactly one connects a group. True for the caller that took it.
     */
    public function claim(string $code): bool
    {
        $row = $this->row();
        if ($row === null || !self::same(self::outstanding($row), $code)) {
            return false;
        }

        return ReportChat::query()
            ->whereKey($row->id)
            ->where('code', $row->getRawOriginal('code'))
            ->update(['code' => null, 'code_expires_at' => null]) === 1;
    }

    /** A group was offered with the code but refused — the screen says why while the code is still out. */
    public function recordAttempt(string $title, GroupProblem $problem): void
    {
        ReportChat::query()->update(['attempt_title' => $title, 'attempt_problem' => $problem->value, 'attempted_at' => now()]);
    }

    /** @return array{title: string, problem: GroupProblem, at: Carbon}|null The last refused attempt, while its code is outstanding */
    public function attempt(): ?array
    {
        $row = $this->row();
        if ($row === null || $row->attempt_problem === null || $row->attempted_at === null || self::outstanding($row) === null) {
            return null;
        }

        return ['title' => (string) $row->attempt_title, 'problem' => $row->attempt_problem, 'at' => $row->attempted_at];
    }

    /** The bot's row as the table holds it now; null while nothing is kept. */
    private function row(): ?ReportChat
    {
        return ReportChat::query()->first();
    }

    /** @return array{code: string, expires_at: Carbon}|null */
    private static function outstanding(?ReportChat $row): ?array
    {
        if ($row === null || $row->code === null || $row->code_expires_at === null || !$row->code_expires_at->isFuture()) {
            return null;
        }

        return ['code' => $row->code, 'expires_at' => $row->code_expires_at];
    }

    /** @param array{code: string, expires_at: Carbon}|null $outstanding */
    private static function same(?array $outstanding, string $code): bool
    {
        return $outstanding !== null && $code !== '' && hash_equals($outstanding['code'], $code);
    }
}
