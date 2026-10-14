<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Subscriptions\DTO\TestTerms;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Each server's free test accounts: the owner's form for them (whether the server gives tests, and a test's traffic,
 * hours and "on hold" — counted from the first connection or from the moment it is made), and which servers give a
 * test now: switched on for tests, able to take a client (ServerSelector::wholeServer()), and — in an agent's shop —
 * with a test's traffic left in the agent's pool.
 */
final class TestServers
{
    /** The most a test's traffic may be, MB (100 GB). */
    public const TRAFFIC_MAX_MB = 102_400;

    /** The longest a test's term may be, hours (30 days). */
    public const HOURS_MAX = 720;

    public function __construct(private readonly ServerSelector $selector) {}

    /**
     * The servers a customer may take a test on now, in the servers' order, each with its terms and the inbounds the
     * test gets.
     *
     * @return list<array{server: Server, terms: TestTerms, inbounds: Collection<int, ServerInbound>}>
     */
    public function choices(): array
    {
        return $this->among(Server::query());
    }

    /**
     * One of choices(), by server id — only that server read; null while it gives no test now.
     *
     * @return array{server: Server, terms: TestTerms, inbounds: Collection<int, ServerInbound>}|null
     */
    public function choice(int $serverId): ?array
    {
        return $this->among(Server::query()->whereKey($serverId))[0] ?? null;
    }

    /**
     * Save the server's test terms (the owner's form: every refusal at once, under its field).
     *
     * @param array<string, mixed> $input
     */
    public function update(Server $server, array $input): Server
    {
        $values = self::form()->check($input, self::kept($server));
        $server->forceFill([
            'test_enabled' => $values['test_enabled'],
            'test_traffic_bytes' => $values['test_traffic_mb'] * Traffic::MEGABYTE,
            'test_duration_hours' => $values['test_duration_hours'],
            'test_on_hold' => $values['test_on_hold'],
        ])->save();

        return $server;
    }

    /**
     * The server's test terms as the form shows them.
     *
     * @return array{test_enabled: bool, test_traffic_mb: int, test_duration_hours: int, test_on_hold: bool}
     */
    public static function present(Server $server): array
    {
        /** @var array{test_enabled: bool, test_traffic_mb: int, test_duration_hours: int, test_on_hold: bool} */
        return self::form()->present(self::kept($server));
    }

    /**
     * The `$servers` giving a test now, as choices() lists them: read in one go with their room and inbounds.
     *
     * @param Builder<Server> $servers
     * @return list<array{server: Server, terms: TestTerms, inbounds: Collection<int, ServerInbound>}>
     */
    private function among(Builder $servers): array
    {
        $shop = CurrentBot::get();
        $pool = $shop->isMain() ? null : $shop->trafficBalance();
        $choices = [];
        foreach ($servers->where('test_enabled', true)->withCount(Server::activeServicesCount())->with('inbounds')->orderBy('sort')->orderBy('id')->get() as $server) {
            $terms = TestTerms::of($server);
            $inbounds = $pool === null || $pool >= $terms->bytes ? $this->selector->wholeServer($server) : null;
            if ($inbounds !== null) {
                $choices[] = ['server' => $server, 'terms' => $terms, 'inbounds' => $inbounds];
            }
        }

        return $choices;
    }

    private static function form(): Form
    {
        return new Form('test_account', [
            new Toggle('test_enabled', 'test_enabled', default: false),
            new Number('test_traffic_mb', 'test_traffic_mb', default: 500, label: 'حجم اکانت تست', min: 1, max: self::TRAFFIC_MAX_MB, unit: 'مگابایت'),
            new Number('test_duration_hours', 'test_duration_hours', default: 24, label: 'مدت اکانت تست', min: 1, max: self::HOURS_MAX, unit: 'ساعت'),
            new Toggle('test_on_hold', 'test_on_hold', default: true),
        ]);
    }

    /**
     * What the server keeps under each field's key: its columns, the traffic in whole MB.
     *
     * @return \Closure(Field<mixed>): mixed
     */
    private static function kept(Server $server): \Closure
    {
        return static fn(Field $field): mixed => match ($field->key) {
            'test_traffic_mb' => intdiv($server->test_traffic_bytes, Traffic::MEGABYTE),
            default => $server->getAttribute($field->key),
        };
    }
}
