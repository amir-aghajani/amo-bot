<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Core\Database\Sorting;
use App\Core\Drivers\Descriptor;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\ServerInUseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Forms\Capacity;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Input;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Everything the owner does with a server: add and edit it — by its form, one for every connector: the server's own
 * columns around its connector's connection form (form()), checked as one, every refusal at once, and kept in the
 * server's columns and `meta` (columns()) —, probe it (stored or not), keep its inbound copy fresh, and present it
 * without its secrets. What a check finds is recorded on the server (ServerHealth).
 */
final class ServerService
{
    private const NAME_MAX = 100;

    /** The owner's notes on a server: a few lines. */
    private const NOTES_MAX = 1000;

    /** Where a field of the form is kept when the server has no column of its name: `meta.<name>`. */
    private const META = 'meta.';

    /** A form naming no connector this installation has. */
    private const NO_DRIVER = 'کانکتور انتخاب‌شده وجود ندارد.';

    /** A saved server's form for another connector than its own. */
    private const DRIVER_FIXED = 'کانکتور سرور عوض نمی‌شود؛ برای پنلی از نوع دیگر، سرور تازه‌ای اضافه کنید.';

    /** What a probe reports when the connection itself failed: nothing beyond the error. */
    private const NOT_PROBED = ['ok' => false, 'status' => null, 'inbounds' => null, 'serves_subscriptions' => null, 'subscription_probed' => false];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServerHealth $health,
        private readonly ServerReadiness $readiness,
        private readonly LoggerInterface $logger,
    ) {}

    // ---------------------------------------------------------------- the connectors

    /**
     * Every connector a server can be added with, in their order, as the add-server picker shows it: its description,
     * its form a server's on it (form()).
     *
     * @return list<array<string, mixed>>
     */
    public function drivers(): array
    {
        return array_map(static function (PanelDriver $driver): array {
            $about = $driver->describe();

            return (new Descriptor($about->key, $about->label, $about->description, self::form($about->form), $about->notes, $about->traits))->toArray();
        }, $this->providers->all());
    }

    // ---------------------------------------------------------------- listing

    /** @return list<array<string, mixed>> Every server with its counts, in the owner's order. */
    public function all(): array
    {
        return array_values(array_map($this->present(...), self::withCounts(Server::query())->oldest('sort')->oldest('id')->get()->all()));
    }

    /** @return array<string, mixed> */
    public function present(Server $server): array
    {
        if (!$server->hasAttribute('inbounds_count')) {
            $server = self::withCounts(Server::query())->findOrFail($server->id);
        }
        $about = $this->providers->find($server->driver)?->describe();

        return [
            'id' => $server->id,
            'name' => $server->name,
            'driver' => $server->driver,
            'driver_label' => $about->label ?? $server->driver,
            'base_url' => $server->base_url,
            // The form as the server keeps it — none while this installation has not its connector.
            'form' => $about === null ? null : self::form($about->form)->present(self::kept($server)),
            'is_active' => $server->is_active,
            'capacity' => $server->capacity,
            'sort' => $server->sort,
            'notes' => $server->notes,
            'last_checked_at' => $server->last_checked_at?->toIso8601String(),
            'last_error' => $server->last_error,
            'serves_subscriptions' => $server->serves_subscriptions,
            'unsellable_reason' => $this->readiness->problem($server),
            'counts' => [
                'inbounds' => (int) $server->getAttribute('inbounds_count'),
                'selectable_inbounds' => (int) $server->getAttribute('selectable_inbounds_count'),
                'active_subscriptions' => (int) $server->getAttribute('active_subscriptions_count'),
                'shop_active_subscriptions' => (int) $server->getAttribute('shop_active_subscriptions_count'),
            ],
            'created_at' => $server->created_at->toIso8601String(),
            'updated_at' => $server->updated_at->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> The inbounds the shop keeps for the server, in the order they were first seen. */
    public function presentInbounds(Server $server): array
    {
        return array_values(array_map(static fn(ServerInbound $row): array => self::presentInbound(self::infoOf($row)) + [
            'id' => $row->id,
            'is_selectable' => $row->is_selectable,
            'synced_at' => $row->synced_at?->toIso8601String(),
        ], $server->inbounds()->oldest('id')->get()->all()));
    }

    // ---------------------------------------------------------------- writing

    /**
     * Check the whole form of the connector it names — every refusal at once — and store the server, last in the owner's
     * order. check() it next.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(array $input): Server
    {
        $driver = $this->driverOf($input, null);
        $form = self::form($driver->describe()->form);

        return Server::query()->create(self::columns($form, $form->check($input, static fn(): mixed => null)) + ['driver' => $driver->key(), 'sort' => Sorting::next(Server::class)]);
    }

    /**
     * Same check as create(), of the server's own connector; a blank secret keeps the stored one while the address stays
     * on its host.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Server $server, array $input): Server
    {
        $form = self::form($this->driverOf($input, $server)->describe()->form);
        $server->fill(self::columns($form, $form->check($input, self::kept($server))))->save();

        return $server;
    }

    /**
     * Refused while services — any bot's — are on the server: moved elsewhere or deleted first, or the server switched
     * off instead. The orders that sold them keep their record either way; the grants' parts on it go with it — here,
     * since no foreign key can cascade there (database/schema.php, grant_parts).
     *
     * @throws ServerInUseException
     */
    public function delete(Server $server): void
    {
        $services = $server->subscriptions()->count();
        if ($services > 0) {
            throw new ServerInUseException(sprintf(
                '%s اشتراک روی این سرور است؛ اول آن‌ها را به سرور دیگری منتقل یا حذف کنید، یا به‌جای حذف، سرور را غیرفعال کنید.',
                Persian::number($services),
            ));
        }

        $server->getConnection()->transaction(static function () use ($server): void {
            GrantPart::query()->where('server_id', $server->id)->delete();
            $server->delete();
        });
    }

    /**
     * The owner opting an inbound in for sale — only one the panel still has enabled.
     *
     * @throws ValidationException
     */
    public function setSelectable(ServerInbound $inbound, bool $selectable): void
    {
        if ($selectable && !$inbound->enabled) {
            throw new ValidationException(['is_selectable' => ['اینباند غیرفعال است.']], 'این اینباند در پنل غیرفعال است و نمی‌تواند برای فروش انتخاب شود.');
        }

        $inbound->forceFill(['is_selectable' => $selectable])->save();
    }

    // ---------------------------------------------------------------- probing

    /**
     * Talk to the panel and report what the connector sees. Works on a server not stored yet, built from the form's
     * input, so credentials are tried before anything is saved. Once the connection is good, each further question is
     * asked on its own, so one flaky endpoint loses only its own answer: `status` null (or a panel without host
     * statistics), `inbounds` null when they could not be listed, `serves_subscriptions` null (`subscription_probed`
     * false) when that could not be asked. Inbounds carry no client secrets.
     *
     * @return array{ok: bool, error: string|null, error_detail: string|null, status: array<string, mixed>|null, inbounds: list<array<string, mixed>>|null, serves_subscriptions: bool|null, subscription_probed: bool}
     */
    public function probe(Server $server): array
    {
        $provider = $this->providers->forServer($server);

        try {
            $provider->testConnection();
        } catch (ProviderException $e) {
            ['message' => $message, 'detail' => $detail] = ProviderErrorPresenter::explain($e);

            return ['error' => $message, 'error_detail' => $detail !== '' ? $detail : null] + self::NOT_PROBED;
        }

        $status = $this->ask($server, 'status', static fn(): array => self::presentStatus($provider->status()));
        $inbounds = $this->ask($server, 'inbounds', static fn(): array => array_map(self::presentInbound(...), $provider->listInbounds()));
        $servesSubscriptions = $this->ask($server, 'subscription server', static fn(): bool => $provider->servesSubscriptions());

        return [
            'ok' => true,
            'error' => null,
            'error_detail' => null,
            'status' => $status,
            'inbounds' => $inbounds,
            'serves_subscriptions' => $servesSubscriptions,
            'subscription_probed' => $servesSubscriptions !== null,
        ];
    }

    /**
     * Probe the form's input without saving anything; `id` names the server being edited, so blank secrets fall back to
     * what it keeps (while the address stays on its host).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error: string|null, error_detail: string|null, status: array<string, mixed>|null, inbounds: list<array<string, mixed>>|null, serves_subscriptions: bool|null, subscription_probed: bool}
     * @throws ValidationException
     */
    public function probeInput(array $input): array
    {
        $id = Input::integer($input, 'id');
        $existing = $id !== null ? Server::query()->find($id) : null;
        $driver = $this->driverOf($input, $existing);
        $form = self::form($driver->describe()->form);
        $values = $form->check($input, $existing === null ? static fn(): mixed => null : self::kept($existing));

        return $this->probe(new Server(self::columns($form, $values) + ['driver' => $driver->key()]));
    }

    /**
     * Probe a stored server and keep what it found: how the contact went, whether the panel serves subscription links
     * (which decides whether the server can be sold at all — a probe that could not ask leaves the last answer), and
     * its inbounds — only when they were listed: a listing that failed changes nothing.
     *
     * @return array{ok: bool, error: string|null, error_detail: string|null, status: array<string, mixed>|null, inbounds: list<array<string, mixed>>|null, serves_subscriptions: bool|null, subscription_probed: bool}
     * @throws ValidationException on `driver`: this installation has not its connector
     */
    public function check(Server $server): array
    {
        $this->connectorOf($server);
        $probe = $this->probe($server);

        $this->health->record($server, $probe['error'] === null ? null : ProviderErrorPresenter::join($probe['error'], (string) $probe['error_detail']));
        if ($probe['serves_subscriptions'] !== null) {
            $server->forceFill(['serves_subscriptions' => $probe['serves_subscriptions']])->save();
        }
        if ($probe['inbounds'] !== null) {
            $this->storeInbounds($server, $probe['inbounds']);
        }

        return $probe;
    }

    /**
     * Read the panel's inbounds into the shop's copy (storeInbounds()).
     *
     * @throws ValidationException on `driver`: this installation has not its connector
     * @throws PanelFailedException when the panel could not list them, in the owner's words
     */
    public function syncInbounds(Server $server): void
    {
        $this->connectorOf($server);
        try {
            $inbounds = $this->providers->forServer($server)->listInbounds();
        } catch (ProviderException $e) {
            $this->health->contacted($server, $e);

            throw new PanelFailedException('دریافت اینباندها از پنل ناموفق بود: ' . ProviderErrorPresenter::describe($e), $e);
        }

        $this->health->record($server, null);
        $this->storeInbounds($server, array_map(self::presentInbound(...), $inbounds));
    }

    // ---------------------------------------------------------------- internals

    /**
     * A saved server's panel is talked to only through its connector: one left from an installation that had it is
     * refused in words (ServerReadiness's), nothing asked nor recorded.
     *
     * @throws ValidationException on `driver`
     */
    private function connectorOf(Server $server): void
    {
        if (!$this->providers->has($server->driver)) {
            throw ValidationException::on('driver', ServerReadiness::NO_CONNECTOR);
        }
    }

    /**
     * The connector a form names (`driver`): one this installation has — a saved server's own, which never changes.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException on `driver`
     */
    private function driverOf(array $input, ?Server $existing): PanelDriver
    {
        $key = Input::text($input, 'driver');
        if ($existing !== null && $key !== $existing->driver) {
            throw ValidationException::on('driver', self::DRIVER_FIXED);
        }

        return $this->providers->find($key) ?? throw ValidationException::on('driver', self::NO_DRIVER);
    }

    /**
     * A server's form on a connector: its name, the connector's connection form, then — under «تنظیمات پیشرفته», after
     * the connection's own options — how many services it takes, the owner's notes and its switch.
     */
    private static function form(Form $connection): Form
    {
        return new Form($connection->key, [
            new Text('name', 'name', '', label: 'نام سرور', max: self::NAME_MAX, required: true, spec: new FieldSpec('نام سرور', hint: 'مشتری این نام را به‌عنوان لوکیشن سرویس می‌بیند.', placeholder: 'مثلا آلمان ۱')),
            ...$connection->fields,
            new Capacity('capacity', 'capacity', spec: new FieldSpec('ظرفیت', hint: 'حداکثر اشتراک فعال روی این سرور؛ خالی = نامحدود.', placeholder: '200', advanced: true)),
            new Text('notes', 'notes', '', label: 'یادداشت', max: self::NOTES_MAX, type: FieldType::Textarea, spec: new FieldSpec('یادداشت', placeholder: 'مثلا محل دیتاسنتر، تاریخ تمدید، …', advanced: true)),
            new Toggle('is_active', 'is_active', true, spec: new FieldSpec('فعال', hint: 'سرورهای غیرفعال برای اشتراک‌های جدید انتخاب نمی‌شوند.', advanced: true)),
        ]);
    }

    /**
     * The server's columns a checked form goes to: each field's value under its key — a column of the server's, or a key
     * of its `meta` (`meta.<name>`, left out while blank) —, a blank one null, and every field the check did not read
     * emptied: the other way in's credentials, which the form shows only with their way — a server keeps one way in. The
     * way in itself is no column (PanelCredentials::MODE): it is which credentials are kept.
     *
     * @param array<string, mixed> $values Form::check()'s, by key
     * @return array<string, mixed>
     */
    private static function columns(Form $form, array $values): array
    {
        $columns = [];
        $meta = [];
        foreach ($form->fields as $field) {
            if ($field->key === PanelCredentials::MODE) {
                continue;
            }
            $value = $values[$field->key] ?? null;
            $value = $value === '' ? null : $value;
            if (!str_starts_with($field->key, self::META)) {
                $columns[$field->key] = $value;
            } elseif ($value !== null) {
                $meta[substr($field->key, strlen(self::META))] = $value;
            }
        }

        return $columns + ['meta' => $meta];
    }

    /**
     * What a stored server keeps under its form's keys (columns()'s other way): a column of its, a key of its `meta` —
     * and, for the way in, which credentials it keeps.
     *
     * @return \Closure(Field<mixed>): mixed
     */
    private static function kept(Server $server): \Closure
    {
        return static fn(Field $field): mixed => match (true) {
            $field->key === PanelCredentials::MODE => PanelCredentials::of($server)->mode->value,
            str_starts_with($field->key, self::META) => $server->meta[substr($field->key, strlen(self::META))] ?? null,
            default => $server->getAttribute($field->key),
        };
    }

    /**
     * One question to a panel whose connection is good: its answer, or null when it could not give one — logged, except
     * from a panel that has no such answer at all (host statistics on a panel that reports none).
     *
     * @template T
     * @param \Closure(): T $question
     * @return T|null
     */
    private function ask(Server $server, string $what, \Closure $question): mixed
    {
        try {
            return $question();
        } catch (UnsupportedOperationException) {
            return null;
        } catch (ProviderException $e) {
            $this->logger->warning('The panel of {server} could not answer for its {what}: {message}', ['server' => $server->name, 'what' => $what, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The panel's inbounds into the shop's copy: new ones are added (not for sale until the owner opts them in), known
     * ones refreshed, and one the panel no longer lists is switched off — kept, with the owner's choice, since services
     * and plans may point at it, and sold again should it come back.
     *
     * @param list<array<string, mixed>> $inbounds As presentInbound() makes them
     */
    private function storeInbounds(Server $server, array $inbounds): void
    {
        $now = now();
        foreach ($inbounds as $inbound) {
            ServerInbound::query()->updateOrCreate(
                ['server_id' => $server->id, 'remote_key' => $inbound['remote_key']],
                array_diff_key($inbound, ['remote_key' => true]) + ['synced_at' => $now],
            );
        }

        $server->inbounds()
            ->where('enabled', true)
            ->whereNotIn('remote_key', array_map(static fn(array $inbound): string => $inbound['remote_key'], $inbounds))
            ->update(['enabled' => false, 'synced_at' => $now]);
    }

    /**
     * The one shape an inbound has in the API, whether it came straight from the panel (a probe) or from the shop's
     * copy (a stored row).
     *
     * @return array{remote_key: string, tag: string, protocol: string|null, port: int|null, remark: string, enabled: bool, network: string|null, security: string|null, client_count: int}
     */
    private static function presentInbound(InboundInfo $inbound): array
    {
        return [
            'remote_key' => $inbound->key,
            'tag' => $inbound->tag,
            'protocol' => $inbound->protocol,
            'port' => $inbound->port,
            'remark' => $inbound->remark,
            'enabled' => $inbound->enabled,
            'network' => $inbound->network,
            'security' => $inbound->security,
            'client_count' => $inbound->clientCount,
        ];
    }

    private static function infoOf(ServerInbound $row): InboundInfo
    {
        return new InboundInfo(
            key: $row->remote_key,
            tag: $row->tag,
            remark: (string) $row->remark,
            enabled: $row->enabled,
            protocol: $row->protocol,
            port: $row->port,
            network: $row->network,
            security: $row->security,
            clientCount: $row->client_count,
        );
    }

    /** @return array<string, mixed> */
    private static function presentStatus(PanelStatus $status): array
    {
        return [
            'core_name' => $status->coreName,
            'core_state' => $status->coreState->value,
            'core_version' => $status->coreVersion,
            'core_error' => $status->coreError,
            'cpu_percent' => $status->cpuPercent,
            'memory_used' => $status->memoryUsedBytes,
            'memory_total' => $status->memoryTotalBytes,
            'disk_used' => $status->diskUsedBytes,
            'disk_total' => $status->diskTotalBytes,
            'uptime_seconds' => $status->uptimeSeconds,
            'connections' => $status->connections,
        ];
    }

    /**
     * Its inbounds, and its running services — every shop's, and the shop's the panel has open (CurrentBot: the servers
     * are worked on everywhere(), whose rows it hides none of, but the panel still has a shop open), the ones its
     * subscriptions screen lists.
     *
     * @param Builder<Server> $query
     * @return Builder<Server>
     */
    private static function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            ...Server::activeServicesCount(),
            'subscriptions as shop_active_subscriptions_count' => static fn(Builder $services) => Subscription::active($services)->where($services->qualifyColumn('bot_id'), CurrentBot::id()),
            'inbounds',
            'inbounds as selectable_inbounds_count' => static fn(Builder $q) => $q->where('is_selectable', true)->where('enabled', true),
        ]);
    }
}
