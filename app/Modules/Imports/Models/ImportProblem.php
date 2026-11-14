<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Modules\Imports\Enums\ProblemReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something an import could not bring, and why, for its report: the kind of thing, the other bot's key for it, the
 * reason, and in `detail` what the owner needs to find it (a customer's Telegram id, a client's name and panel …) or
 * the panel's own words.
 *
 * @property int $id
 * @property int $import_id
 * @property string $kind
 * @property string $source_key
 * @property ProblemReason $reason
 * @property string|null $detail
 * @property Carbon|null $created_at
 */
class ImportProblem extends Model
{
    protected $table = 'import_problems';

    public const UPDATED_AT = null;

    protected $fillable = ['import_id', 'kind', 'source_key', 'reason', 'detail'];

    /** @var array<string, string> */
    protected $casts = [
        'import_id' => 'integer',
        'reason' => ProblemReason::class,
    ];
}
