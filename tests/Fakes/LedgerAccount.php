<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

/**
 * Whose running balance a ledger keeps — LedgerTest's owner, its lines in LINES (whole numbers, as an agent's traffic
 * is): tables of their own that the test makes inside its transaction (gone with it).
 *
 * @property int $id
 * @property string $name
 */
final class LedgerAccount extends Model
{
    public const LINES = 'ledger_lines';

    public $timestamps = false;

    protected $table = 'ledger_accounts';

    protected $fillable = ['name'];

    public static function makeTables(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->create('ledger_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 32);
        });
        $schema->create(self::LINES, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
        });
    }
}
