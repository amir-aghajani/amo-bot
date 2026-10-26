<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Notifications\Enums\NoticeSubject;
use App\Modules\Notifications\Enums\NoticeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A notice the shop sent a customer, kept for their website feed (Services\Notices): its kind, its text as the bot
 * wrote it (Telegram HTML; the caption when it went as a QR card), what it is about, and whether they read it there.
 *
 * @property int $id
 * @property int $user_id
 * @property NoticeType $type
 * @property string $text Telegram HTML, as the bot words it
 * @property NoticeSubject|null $subject_type What it is about: one of the customer's orders, services or tickets; null for none
 * @property int|null $subject_id
 * @property Carbon|null $read_at When the customer read it on the website; null: not yet
 * @property Carbon|null $created_at
 */
class Notification extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $fillable = ['user_id', 'type', 'text', 'subject_type', 'subject_id'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'type' => NoticeType::class,
        'subject_type' => NoticeSubject::class,
        'subject_id' => 'integer',
        'read_at' => 'datetime',
    ];
}
