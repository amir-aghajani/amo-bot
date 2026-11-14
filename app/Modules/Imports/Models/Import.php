<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Enums\ImportStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import of another bot's data into a shop («انتقال از ربات دیگر»). Reading keeps the source's cursor in `reading`
 * (the source's own shape: Sources\Stager), a database's connection encrypted in `connection` until it is read, and
 * the tables it found in `schema`; then `survey` (Survey::toArray()), the panels' connections encrypted in `panels`,
 * the owner's `choices`, and, importing, the `stage` and the last staged row it wrote (`cursor`) and `counts` — each kind's
 * {created, linked, skipped}.
 *
 * @property int $id
 * @property int $bot_id The shop it is brought into
 * @property string $migrator Its Migrator's key
 * @property ImportSource $source
 * @property ImportPhase $phase
 * @property string|null $file_name The backup file's name as uploaded
 * @property array<string, mixed>|null $reading
 * @property string|null $connection Encrypted JSON
 * @property array<string, list<string>>|null $schema
 * @property string|null $version The other bot's version, when its migrator can tell
 * @property array<string, mixed>|null $survey
 * @property string|null $panels Encrypted JSON: each panel's key → its connection (ImportedPanel::$connection)
 * @property array<string, mixed>|null $choices
 * @property ImportStage|null $stage
 * @property int $cursor
 * @property array<string, array<string, int>>|null $counts
 * @property string|null $error Why it failed, in the owner's words
 * @property string|null $reviewer Who started it (Auth\Services\Reviewers)
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Import extends Model
{
    protected $table = 'imports';

    protected $fillable = ['bot_id', 'migrator', 'source', 'phase', 'file_name', 'reading', 'connection', 'schema', 'version', 'survey', 'panels', 'choices', 'stage', 'cursor', 'counts', 'error', 'reviewer', 'started_at', 'finished_at'];

    /** @var array<string, string> */
    protected $casts = [
        'bot_id' => 'integer',
        'source' => ImportSource::class,
        'phase' => ImportPhase::class,
        'reading' => 'array',
        'schema' => 'array',
        'survey' => 'array',
        'choices' => 'array',
        'stage' => ImportStage::class,
        'cursor' => 'integer',
        'counts' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['cursor' => 0];

    /** @return HasMany<ImportProblem, $this> */
    public function problems(): HasMany
    {
        return $this->hasMany(ImportProblem::class);
    }
}
