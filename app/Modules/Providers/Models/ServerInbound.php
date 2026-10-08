<?php

declare(strict_types=1);

namespace App\Modules\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The shop's copy of what a panel attaches clients to (DTO\InboundInfo), as much of it as the admin picks by — refreshed
 * by every check of the server. `enabled` follows the panel (one that vanished from it stays, switched off: services and
 * plans may still point at it); `is_selectable` is the admin opting it in for sale, and stays the admin's.
 *
 * @property int $id
 * @property int $server_id
 * @property string $remote_key The driver's own identifier for it (InboundInfo::$key)
 * @property string $tag
 * @property string|null $protocol vless | vmess | trojan | shadowsocks | …; null when it is not one protocol
 * @property int|null $port null when it has none of its own
 * @property string|null $remark
 * @property bool $enabled
 * @property bool $is_selectable
 * @property string|null $network Transport (tcp, ws, grpc, …)
 * @property string|null $security none | tls | reality …
 * @property int $client_count
 * @property Carbon|null $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerInbound extends Model
{
    protected $table = 'server_inbounds';

    protected $fillable = [
        'server_id',
        'remote_key',
        'tag',
        'protocol',
        'port',
        'remark',
        'enabled',
        'is_selectable',
        'network',
        'security',
        'client_count',
        'synced_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'port' => 'integer',
        'enabled' => 'boolean',
        'is_selectable' => 'boolean',
        'client_count' => 'integer',
        'synced_at' => 'datetime',
    ];
}
