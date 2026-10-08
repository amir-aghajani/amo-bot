<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Telegram\Broadcasts\BroadcastKind;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One «ارسال همگانی» run (see Broadcasts\BroadcastService): an admin's message copied or forwarded to an audience —
 * or, of kind Unpin, the pins of an earlier one taken off again (the message, its mode and audience are its source's:
 * message()).
 *
 * @property int $id
 * @property BroadcastKind $kind
 * @property int|null $source_id An unpin run: the broadcast whose pins it takes off
 * @property int|null $user_id The bot admin who sent it — or pressed «لغو پین» on its progress message, in the same chat; null: started from the panel
 * @property string|null $reviewer The panel login, for a run started there
 * @property int|null $message_id A message run's: the message being sent, in its admin's chat
 * @property BroadcastMode|null $mode A message run's
 * @property string|null $audience A message run's: Broadcasts\Audience's key
 * @property int|null $audience_id The customer group or the server an audience names
 * @property bool $pin Whether each message is pinned in its chat
 * @property list<list<array{text: string, url: string}>>|null $buttons The admin's link buttons under a copy
 * @property string|null $content What the message is: text, photo, video, …
 * @property string|null $excerpt Its text or caption, cut short, for the panel
 * @property BroadcastStatus $status
 * @property int $total Recipients when the run started
 * @property int $sent
 * @property int $blocked Recipients who blocked the bot
 * @property int $failed
 * @property int $last_user_id Cursor: every user up to this id has been handled
 * @property int|null $progress_message_id The admin's live progress message, in their chat (adminChat())
 * @property string|null $lease_token The worker holding the run (Core\Database\Lease)
 * @property Carbon|null $leased_until
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Broadcast|null $source
 */
class Broadcast extends Model
{
    use BelongsToBot;

    /** What withUnpinState() names the run's open unpin runs, when it reads them with the row. */
    private const UNPINNING = 'unpinning';

    protected $table = 'broadcasts';

    protected $fillable = [
        'kind',
        'source_id',
        'user_id',
        'reviewer',
        'message_id',
        'mode',
        'audience',
        'audience_id',
        'pin',
        'buttons',
        'content',
        'excerpt',
        'status',
        'total',
        'progress_message_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => BroadcastKind::class,
        'source_id' => 'integer',
        'user_id' => 'integer',
        'message_id' => 'integer',
        'mode' => BroadcastMode::class,
        'audience_id' => 'integer',
        'pin' => 'boolean',
        'buttons' => 'array',
        'status' => BroadcastStatus::class,
        'total' => 'integer',
        'sent' => 'integer',
        'blocked' => 'integer',
        'failed' => 'integer',
        'last_user_id' => 'integer',
        'progress_message_id' => 'integer',
        'leased_until' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $attributes = [
        'sent' => 0,
        'blocked' => 0,
        'failed' => 0,
        'last_user_id' => 0,
    ];

    /** @return Builder<static> Runs still going, oldest first — not paused ones. */
    public static function sending(): Builder
    {
        return static::query()->where('status', BroadcastStatus::Sending->value)->oldest('id');
    }

    /**
     * Runs with what decides whether their pins can come off (canUnpin()) read in the same query: how many are pinned
     * still, and whether an unpin run of theirs is under way.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public static function withUnpinState(Builder $query): Builder
    {
        return $query->withCount('pins')->withExists(self::openUnpins());
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isUnpin(): bool
    {
        return $this->kind === BroadcastKind::Unpin;
    }

    /**
     * Whether its pins can come off: a pinned message run that is over, with pins still in place and no unpin run of
     * them under way.
     */
    public function canUnpin(): bool
    {
        if ($this->isUnpin() || !$this->pin || $this->isOpen() || $this->pinnedCount() === 0) {
            return false;
        }
        if (!array_key_exists(self::UNPINNING, $this->attributes)) {
            $this->loadExists(self::openUnpins());
        }

        return !$this->getAttribute(self::UNPINNING);
    }

    /** How many of its messages are pinned still — read with the row in a list (withUnpinState()), asked otherwise. */
    public function pinnedCount(): int
    {
        if (!array_key_exists('pins_count', $this->attributes)) {
            $this->loadCount('pins');
        }

        return (int) $this->getAttribute('pins_count');
    }

    /**
     * The admin's private chat with the bot — where a message run's message lives and a run's progress is drawn: its
     * admin's Telegram id. Null for a run started from the panel.
     */
    public function adminChat(): ?int
    {
        return $this->user?->telegram_id;
    }

    /** The message run this one is about: itself, or an unpin run's source — the message, its mode and audience are that run's. */
    public function message(): self
    {
        return $this->isUnpin() ? ($this->source ?? throw new \LogicException("Unpin run #{$this->id} has no source.")) : $this;
    }

    /** @return array{int, int} Where a message run's message is: its admin's chat, and the message's id there. */
    public function original(): array
    {
        $message = $this->message();
        $chat = $message->adminChat();

        return $chat !== null && $message->message_id !== null ? [$chat, $message->message_id] : throw new \LogicException("Broadcast #{$message->id} has no message to send.");
    }

    /** @return array{key: string, id: int|null} A message run's audience (Broadcasts\Audience): its key, and the group or server it names. */
    public function audienceRef(): array
    {
        $message = $this->message();

        return ['key' => $message->audience ?? throw new \LogicException("Broadcast #{$message->id} has no audience."), 'id' => $message->audience_id];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Broadcast, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    /** @return HasMany<BroadcastPin, $this> Where its messages are pinned still. */
    public function pins(): HasMany
    {
        return $this->hasMany(BroadcastPin::class);
    }

    /** @return HasMany<Broadcast, $this> The runs that take its pins off. */
    public function unpins(): HasMany
    {
        return $this->hasMany(self::class, 'source_id');
    }

    /** @return array<string, \Closure(Builder<Broadcast>): mixed> The unpin runs under way, as withExists()/loadExists() name them */
    private static function openUnpins(): array
    {
        return ['unpins as ' . self::UNPINNING => static fn(Builder $query) => $query->whereIn('status', array_map(static fn(BroadcastStatus $status): string => $status->value, BroadcastStatus::OPEN))];
    }
}
