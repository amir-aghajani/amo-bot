<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Payments\DTO\CardTransfer;
use App\Support\BankCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A card a card-to-card method shows: its number (16 bare digits), whose it is, the owner's word for it (`bank`) and how
 * much it may take in a day (`daily_cap`, Toman on the shop's clock; null: no cap). Each payment of the method is shown
 * one of its enabled cards, picked at random (Services\PaymentCards), and always that one after.
 *
 * @property int $id
 * @property int $payment_method_id
 * @property string $number
 * @property string $holder
 * @property string|null $bank
 * @property bool $enabled
 * @property int $sort
 * @property string|null $daily_cap
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PaymentMethod $method
 */
class PaymentCard extends Model
{
    use BelongsToBot;

    protected $table = 'payment_cards';

    protected $fillable = ['payment_method_id', 'number', 'holder', 'bank', 'enabled', 'sort', 'daily_cap'];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
        'sort' => 'integer',
        'daily_cap' => 'decimal:2',
    ];

    /** "6037 •••• •••• 1119 · AmoBot · ملت" — which card, for lists, reports and the payments screen. */
    public function summary(): string
    {
        $parts = [BankCard::mask($this->number), $this->holder, (string) $this->bank];

        return implode(' · ', array_values(array_filter($parts, static fn(string $part): bool => trim($part) !== '')));
    }

    /** Where a payment shown this card goes, with the method's note to the customer. */
    public function transfer(string $instructions): CardTransfer
    {
        return new CardTransfer($this->number, $this->holder, $instructions, $this->bank);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'card_id');
    }
}
