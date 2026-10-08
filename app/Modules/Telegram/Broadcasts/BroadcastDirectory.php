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

/**
 * The panel's view of «ارسال همگانی»: every run — messages a bot admin sent with /broadcast and the «لغو پین» runs —
 * newest first, with how far each got and what its state allows; and the same controls the bot's progress message has
 * (BroadcastService does the work, the bot's message follows). A message is composed in the bot only: it is the
 * admin's own Telegram message that goes out.
 */
final class BroadcastDirectory
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly Reviewers $reviewers,
    ) {}

    public function page(PageRequest $request): Page
    {
        // Each group's or server's name is looked up once for the page, however many runs name it.
        $names = [];
        $query = Broadcast::withUnpinState(Broadcast::query()->with(['user', 'source.user'])->latest('id'));

        return Page::fetch($query, $request, function (Broadcast $broadcast) use (&$names): array {
            return $this->present($broadcast, $names);
        });
    }

    /**
     * Pause, resume or cancel a run from the panel — the bot's progress message follows.
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
     * Take a finished pinned run's pins off again, under the name of whoever asked; the scheduler works it through.
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
     * @param array<string, string> $names Group and server names looked up already, by "key:id" (Audience::labelOf())
     * @return array<string, mixed>
     */
    public function present(Broadcast $broadcast, array &$names = []): array
    {
        ['key' => $audience, 'id' => $audienceId] = $broadcast->audienceRef();

        return [
            'id' => $broadcast->id,
            'kind' => $broadcast->kind->value,
            'source_id' => $broadcast->source_id,
            'mode' => $broadcast->message()->mode?->value,
            'audience' => [
                'key' => $audience,
                'id' => $audienceId,
                'label' => Audience::labelOf($audience, $audienceId, $names),
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
            'created_at' => $broadcast->created_at->toIso8601String(),
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
