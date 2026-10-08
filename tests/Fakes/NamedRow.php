<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

/**
 * A row named uniquely among its shop's, as a customer group is — UniqueNameTest's: its queries see shop 1's rows only
 * (as a shop's rows are the current bot's), on a table of its own that the test makes inside its transaction.
 *
 * @property int $id
 * @property int $shop
 * @property string $name
 */
final class NamedRow extends Model
{
    public const TABLE = 'named_rows';

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $fillable = ['name'];

    /** @var array<string, mixed> */
    protected $attributes = ['shop' => 1];

    public static function makeTable(Connection $db): void
    {
        $db->getSchemaBuilder()->create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('shop');
            $table->string('name', 32);
            $table->unique(['shop', 'name']);
        });
    }

    protected static function booted(): void
    {
        static::addGlobalScope('shop', static fn(Builder $query) => $query->where('shop', 1));
    }
}
