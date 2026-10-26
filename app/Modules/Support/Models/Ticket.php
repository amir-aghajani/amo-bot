<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A support conversation («تیکت») a customer opened on the website or in the bot, optionally about one of their services
 * (Services\Tickets). A customer's message opens it (again), support's answers it, either side closes it, support
 * reopens it; once closed the customer may rate it.
 *
 * @property int $id
 * @property int $bot_id
 * @property int $user_id
 * @property string $subject
 * @property TicketStatus $status
 * @property int|null $subscription_id The customer's service it is about; null for none, or one deleted since
 * @property Carbon|null $last_message_at When its latest message was written (set with every one): the lists' order
 * @property bool $customer_unread Support wrote since the customer last read it
 * @property int|null $rating 1 to 5: the customer's word on it once it was closed; null for none
 * @property string|null $rating_note What they said with it
 * @property Carbon|null $closed_at When it was closed; null while it is not
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, TicketMessage> $messages
 */
class Ticket extends Model
{
    use BelongsToBot;

    protected $table = 'tickets';

    protected $fillable = ['user_id', 'subject', 'status', 'subscription_id', 'last_message_at', 'customer_unread'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'status' => TicketStatus::class,
        'subscription_id' => 'integer',
        'last_message_at' => 'datetime',
        'customer_unread' => 'boolean',
        'rating' => 'integer',
        'closed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => TicketStatus::Open->value,
        'customer_unread' => false,
    ];

    /**
     * The customer's own tickets in the current shop, as their website and the bot read them (another's is simply absent).
     *
     * @return Builder<static>
     */
    public static function of(User $customer): Builder
    {
        return static::query()->where('user_id', $customer->id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<TicketMessage, $this> The conversation, the first message first. */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }
}
