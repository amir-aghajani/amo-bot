<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Core\Database\Sorting;
use App\Core\Drivers\Descriptor;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Choice;
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
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Keyboard\KeyboardLayout;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Text as Words;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Everything the owner does with a server: add and edit it through one form for every connector — the server's own
 * fields around its connector's connection form (form()), checked as one with every refusal at once, kept in its
 * columns and `meta` (columns()) —, probe it (saved or not), keep its inbound copy fresh, and present it without its
 * secrets. What a check finds is recorded on the server (ServerHealth).
 */
final class ServerService
{
    private const NAME_MAX = 100;

    /** The owner's notes on a server: a few lines. */
    private const NOTES_MAX = 1000;

    /** Where a field is kept when the server has no column of its name: `meta.<name>`. */
    private const META = 'meta.';

    /** A form naming no connector this installation has. */
    private const NO_DRIVER = 'کانکتور انتخاب‌شده وجود ندارد.';

    /** A saved server's form for another connector than its own. */
    private const DRIVER_FIXED = 'کانکتور سرور عوض نمی‌شود؛ برای پنلی از نوع دیگر، سرور تازه‌ای اضافه کنید.';

    /** The longest words of an inbound the shop's copy keeps (server_inbounds), as the panel's admin typed them. */
    private const INBOUND_WORDS = ['tag' => 128, 'remark' => 255];

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
     * Every connector a server can be added with, in order, as the add-server picker shows it: its description with the
     * whole server form (form()).
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

    /** @return array<string, mixed> The server for the screens, its secrets as whether one is kept (its counts read when missing). */
    public function present(Server $server): array
    {
        if (!$server->hasAttribute('inbounds_count')) {
            $server = self::withCounts(Server::query())->findOrFail($server->id);
        }
        $about = $this->providers->find($server->driver)?->describe();
        // Kept on one line (ProviderErrorPresenter::join()), shown on two: the diagnosis, and the panel's or cURL's words.
        $lastError = $server->last_error === null ? null : ProviderErrorPresenter::split($server->last_error);

        return [
            'id' => $server->id,
            'name' => $server->name,
            'driver' => $server->driver,
            'driver_label' => $about->label ?? $server->driver,
            'base_url' => $server->base_url,
            // The form as the server keeps it; none while this installation lacks its connector.
            'form' => $about === null ? null : self::form($about->form)->present(self::kept($server)),
            'is_active' => $server->is_active,
            'capacity' => $server->capacity,
            'sort' => $server->sort,
            'notes' => $server->notes,
            'last_checked_at' => $server->last_checked_at?->toIso8601String(),
            'last_error' => $lastError['message'] ?? null,
            'last_error_detail' => ($lastError['detail'] ?? '') !== '' ? $lastError['detail'] : null,
            'serves_subscriptions' => $server->serves_subscriptions,
            'unsellable_reason' => $this->readiness->problem($server),
            'counts' => [
                'inbounds' => (int) $server->getAttribute('inbounds_count'),
                'selectable_inbounds' => (int) $server->getAttribute('selectable_inbounds_count'),
                'active_subscriptions' => (int) $server->getAttribute('active_subscriptions_count'),
                'shop_active_subscriptions' => (int) $server->getAttribute('shop_active_subscriptions_count'),
            ],
            'created_at' => $server->created_at?->toIso8601String(),
            'updated_at' => $server->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> The shop's copy of the server's inbounds, first seen first. */
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
     * order. It cannot be sold until its first check().
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
     * Same check as create(), on the server's own connector (another one is refused); a blank secret keeps the stored one
     * while the address stays on its host.
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
     * Delete the server; refused while a move of services from or to it is open (queued, running or paused: it would
     * go with it), or while any bot's services are on it (move or delete them first, or switch the server off instead).
     * Its grant parts and the moves of services from or to it go with it here: MySQL allows no cascading foreign key on a
     * column a generated column is computed from (grant_parts.running_server_id, server_migrations.open_source_id).
     *
     * @throws ServerInUseException
     */
    public function delete(Server $server): void
    {
        $move = ServerMigration::open()->whereAny(['source_server_id', 'target_server_id'], $server->id)->with(['source', 'target'])->first();
        if ($move !== null) {
            throw new ServerInUseException($move->source_server_id === $server->id
                ? sprintf('سرویس‌های این سرور در حال انتقال به سرور «%s» است؛ صبر کنید تمام شود یا آن را لغو کنید.', $move->target->name ?? '')
                : sprintf('سرویس‌های سرور «%s» در حال انتقال به این سرور است؛ صبر کنید تمام شود یا آن را لغو کنید.', $move->source->name ?? ''));
        }

        $services = $server->subscriptions()->count();
        if ($services > 0) {
            throw new ServerInUseException(sprintf(
                '%s اشتراک روی این سرور است؛ اول آن‌ها را به سرور دیگری منتقل یا حذف کنید، یا به‌جای حذف، سرور را غیرفعال کنید.',
                Persian::number($services),
            ));
        }

        $server->getConnection()->transaction(static function () use ($server): void {
            GrantPart::query()->where('server_id', $server->id)->delete();
            // Its moves' history, either way (no cascade: open_source_id); the clients they left on another panel stay
            // listed there, those on its own go with it.
            ServerMigration::query()->whereAny(['source_server_id', 'target_server_id'], $server->id)->delete();
            $server->delete();
        });
    }

    /**
     * Opt an inbound in or out for sale; only one the panel has enabled may be opted in.
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
     * Talk to the panel and report what the connector sees; works on a server not stored yet, so credentials are tried
     * before anything is saved. Once the connection is good each further question is asked on its own, so one flaky
     * endpoint loses only its answer: `status` null (or a panel without host statistics), `inbounds` null when they could
     * not be listed, `serves_subscriptions` null (`subscription_probed` false) when that could not be asked.
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
     * Probe the form's input without saving anything; `id` names the server being edited, whose stored secrets the blank
     * ones stand for (while the address stays on its host).
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
     * (which decides whether it can be sold; a probe that could not ask leaves the last answer), and its inbounds — only
     * when they were listed, so a failed listing changes nothing.
     *
     * @return array{ok: bool, error: string|null, error_detail: string|null, status: array<string, mixed>|null, inbounds: list<array<string, mixed>>|null, serves_subscriptions: bool|null, subscription_probed: bool}
     * @throws ValidationException on `driver`: this installation lacks its connector
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
     * @throws ValidationException on `driver`: this installation lacks its connector
     * @throws PanelFailedException when the panel could not list them (worded for the owner)
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
     * Refuse a server whose connector this installation lacks, before anything is asked or recorded.
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
     * The connector a form names (`driver`): one this installation has, and a saved server's own (it never changes).
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
     * A server's whole form on a connector: its name, the connector's connection form, then under «تنظیمات پیشرفته» its
     * capacity, the owner's notes, its switch, and its button among a plan's locations in the bot (colour, and how many
     * share its row).
     */
    private static function form(Form $connection): Form
    {
        return new Form($connection->key, [
            new Text('name', 'name', '', label: 'نام سرور', max: self::NAME_MAX, required: true, spec: new FieldSpec('نام سرور', hint: 'مشتری این نام را به‌عنوان لوکیشن سرویس می‌بیند.', placeholder: 'مثلا آلمان ۱')),
            ...$connection->fields,
            new Capacity('capacity', 'capacity', spec: new FieldSpec('ظرفیت', hint: 'حداکثر اشتراک فعال روی این سرور؛ خالی = نامحدود.', placeholder: '200', advanced: true)),
            new Text('notes', 'notes', '', label: 'یادداشت', max: self::NOTES_MAX, type: FieldType::Textarea, spec: new FieldSpec('یادداشت', placeholder: 'مثلا محل دیتاسنتر، تاریخ تمدید، …', advanced: true)),
            new Toggle('is_active', 'is_active', true, spec: new FieldSpec('فعال', hint: 'سرورهای غیرفعال برای اشتراک‌های جدید انتخاب نمی‌شوند.', advanced: true)),
            new Choice('button_style', self::META . 'button_style', '', ['', ...KeyboardLayout::STYLES], KeyboardLayout::STYLE_REFUSED, required: false, spec: new FieldSpec(
                'رنگ دکمه در ربات',
                hint: 'دکمه این لوکیشن در صفحه انتخاب سرور هر پلن، در ربات همه فروشگاه‌ها.',
                advanced: true,
                options: ['' => 'پیش‌فرض تلگرام', 'primary' => 'آبی', 'success' => 'سبز', 'danger' => 'قرمز'],
            )),
            new Choice('button_row', self::META . 'button_row', '1', array_map(strval(...), range(1, KeyboardLayout::ROW_MOST)), KeyboardLayout::ROW_REFUSED, required: false, spec: new FieldSpec(
                'جای دکمه در ربات',
                hint: 'دکمه‌های پشت سر هم با یک اندازه کنار هم در یک ردیف می‌نشینند.',
                advanced: true,
                options: ['1' => 'یک ردیف کامل', '2' => 'دو دکمه در ردیف', '3' => 'سه دکمه در ردیف'],
            )),
        ]);
    }

    /**
     * The server's columns from a checked form: each field's value under its key — a column, or a key of `meta` (left
     * out while blank) —, blank as null. Fields the check did not read (the other way in's credentials) are emptied, so a
     * server keeps one way in. The way in itself is no column (PanelCredentials::MODE).
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
     * What a stored server keeps under its form's keys (columns() in reverse); the way in is read off which credentials
     * it keeps.
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
     * One question to a panel whose connection is good: its answer, or null when it gave none — logged, unless the panel
     * simply has no such answer (UnsupportedOperationException).
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
     * Store the panel's inbounds: new ones added (not for sale until the owner opts them in), known ones refreshed, and
     * one the panel no longer lists switched off — kept with the owner's choice, since services and plans may point at
     * it, and sold again should it come back. Their words are cut to what the copy keeps (INBOUND_WORDS): a panel sets
     * no such limit.
     *
     * @param list<array<string, mixed>> $inbounds As presentInbound() makes them
     */
    private function storeInbounds(Server $server, array $inbounds): void
    {
        $now = now();
        foreach ($inbounds as $inbound) {
            foreach (self::INBOUND_WORDS as $column => $max) {
                $inbound[$column] = Words::fit((string) $inbound[$column], $max);
            }
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
     * An inbound in the API's one shape, straight from the panel (a probe) or from the shop's copy.
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
     * Read with each server: its inbounds, and its active services — every shop's (what its capacity counts), and the
     * open shop's (what that shop's subscriptions screen lists; CurrentBot still names it while working everywhere()).
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
