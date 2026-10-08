<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

/**
 * A run worked through past a cursor under a lease — LeaseTest's row, on a table of its own that the test makes inside
 * its transaction (gone with it).
 *
 * @property int $id
 * @property string $status
 * @property int $cursor
 * @property int $sent
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 */
final class LeasedRun extends Model
{
    public $timestamps = false;

    protected $table = 'lease_runs';

    protected $fillable = ['status', 'cursor', 'sent', 'lease_token', 'leased_until'];

    /** @var array<string, string> */
    protected $casts = ['cursor' => 'integer', 'sent' => 'integer', 'leased_until' => 'datetime'];

    public static function makeTable(Connection $db): void
    {
        $db->getSchemaBuilder()->create('lease_runs', static function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16);
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
        });
    }
}
