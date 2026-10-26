<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Collection;

/**
 * The panel's «ارسال همگانی» page: every run, newest first, with its progress and allowed actions, and the same
 * controls the bot's progress message has. Messages are composed in the bot only (it is the admin's own message that
 * goes out).
 */
final class BroadcastDirectory
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly Reviewers $reviewers,
    ) {}

    /** A page of every run, newest first. */
    public function page(PageRequest $request): Page
    {
        return Page::fetchTogether(Broadcast::withUnpinState(Broadcast::query()->with(['user', 'source.user'])->latest('id')), $request, $this->presentAll(...));
    }

    /**
     * Pause, resume or cancel a run from the panel.
     *
     * @throws ValidationException on `status` when its state does not allow it (moved on meanwhile)
     */
    public function control(Broadcast $broadcast, BroadcastControl $control): void
    {
        if (!$this->broadcasts->control($broadcast, $control)) {
            throw ValidationException::on('status', Messages::BROADCAST_UNCHANGED);
        }
    }

    /**
     * Take a finished pinned run's pins off, recorded under whoever asked; the scheduler works it through.
     *
     * @throws ValidationException on `status`
     */
    public function unpin(Broadcast $broadcast, Actor $actor): Broadcast
    {
        return $this->broadcasts->startUnpin($broadcast, null, $actor->reviewer);
    }

    /**
     * A run — an unpin run with its source's mode and audience.
     *
     * @return array<string, mixed>
     */
    public function present(Broadcast $broadcast): array
    {
        return $this->presentAll(new Collection([$broadcast]))[0];
    }

    /**
     * Runs as the page shows them: each group's or server's name looked up once, however many runs name it, and the
     * names of the shop's admins who started them from its website read once for them all (Reviewers::names()).
     *
     * @param Collection<int, Broadcast> $broadcasts
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $broadcasts): array
    {
        $labels = [];
        $names = $this->reviewers->names($broadcasts->pluck('reviewer'));

        return array_values($broadcasts->map(function (Broadcast $broadcast) use (&$labels, $names): array {
            return $this->row($broadcast, $labels, $names[$broadcast->reviewer ?? ''] ?? null);
        })->all());
    }

    /**
     * present(), with the name of the admin who started it (`$reviewerName`).
     *
     * @param array<string, string> $labels Group and server names looked up already, by "key:id" (Audience::labelOf())
     * @return array<string, mixed>
     */
    private function row(Broadcast $broadcast, array &$labels, ?string $reviewerName): array
    {
        // The message run it is about, its mode and audience — unknown (null) for a row edited by hand to have none.
        $message = $broadcast->messageRun();
        $audience = $message?->audience;

        return [
            'id' => $broadcast->id,
            'kind' => $broadcast->kind->value,
            'source_id' => $broadcast->source_id,
            'mode' => $message?->mode?->value,
            'audience' => $audience === null ? null : [
                'key' => $audience,
                'id' => $message->audience_id,
                'label' => Audience::labelOf($audience, $message->audience_id, $labels),
            ],
            'pin' => $broadcast->pin,
            'pinned' => $broadcast->isUnpin() ? 0 : $broadcast->pinnedCount(),
            'buttons' => $broadcast->buttons ?? [],
            'content' => $broadcast->content,
            'excerpt' => $broadcast->excerpt,
            'status' => $broadcast->status->value,
            'total' => $broadcast->total,
            'sent' => $broadcast->sent,
            'blocked' => $broadcast->blocked,
            'failed' => $broadcast->failed,
            'admin' => $broadcast->user !== null ? UserDirectory::presentRef($broadcast->user) : null,
            'reviewer' => $this->reviewers->present($broadcast->reviewer),
            'reviewer_name' => $reviewerName,
            'created_at' => $broadcast->created_at?->toIso8601String(),
            'finished_at' => $broadcast->finished_at?->toIso8601String(),
            'actions' => [
                'pause' => $broadcast->status === BroadcastStatus::Sending,
                'resume' => $broadcast->status === BroadcastStatus::Paused,
                'cancel' => $broadcast->isOpen(),
                'unpin' => $broadcast->canUnpin(),
            ],
        ];
    }
}
