<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One way to pay, as the customer sees it at checkout: an instance of a gateway driver with that
 * driver's settings (`config`) and the admin's label — "کارت به کارت (ملت)". Several rows may share a
 * driver (one per card); the wallet is the built-in row every shop has.
 *
 * @property int $id
 * @property string $driver
 * @property string $label
 * @property array<string, mixed> $config
 * @property bool $enabled
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentMethod extends Model
{
    use BelongsToBot;

    protected $table = 'payment_methods';

    protected $fillable = ['driver', 'label', 'config', 'enabled', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Everything the customer may pick, in checkout order.
     *
     * @return Builder<static>
     */
    public static function enabled(): Builder
    {
        return static::query()->where('enabled', true)->oldest('sort')->oldest('id');
    }

    /** The built-in wallet: it pays from the customer's balance (and an agent's credit), so it cannot charge the wallet. */
    public function isWallet(): bool
    {
        return $this->driver === WalletGateway::key();
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
