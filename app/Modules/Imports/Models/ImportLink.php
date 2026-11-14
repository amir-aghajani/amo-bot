<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What an import brought: a thing of the other bot, by its own key, and what it became in the shop — a customer's row,
 * a service's, the wallet line their balance came as. One per thing per shop and migrator, whichever import made it:
 * what a later import of the same bot recognises, and does not bring twice.
 *
 * @property int $id
 * @property int $bot_id
 * @property string $migrator
 * @property string $kind
 * @property string $source_key
 * @property int|null $target_id
 * @property int|null $import_id
 * @property Carbon|null $created_at
 */
class ImportLink extends Model
{
    protected $table = 'import_links';

    public const UPDATED_AT = null;

    protected $fillable = ['bot_id', 'migrator', 'kind', 'source_key', 'target_id', 'import_id'];

    /** @var array<string, string> */
    protected $casts = [
        'bot_id' => 'integer',
        'target_id' => 'integer',
        'import_id' => 'integer',
    ];
}
