<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A staged row of another bot's table (Staging\Staged): its `id` is its seq, `data` a JSON object of strings and nulls
 * whose secret columns are encrypted. Written and read in bulk by the staging code, never one by one.
 *
 * @property int $id
 * @property int $import_id
 * @property string $table_name
 * @property string $data
 */
class ImportRow extends Model
{
    protected $table = 'import_rows';

    public $timestamps = false;

    protected $fillable = ['import_id', 'table_name', 'data'];

    /** @var array<string, string> */
    protected $casts = ['import_id' => 'integer'];
}
