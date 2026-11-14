<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Security\Encrypter;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Models\Import;
use App\Modules\Providers\Models\Server;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of an import's writing as its writers share it: the import, the owner's choices, the panels of its survey
 * and the servers they map to, and the tally kept in the import's `counts` — what each kind came to (KINDS: created,
 * linked to what the shop had, skipped), the reasons only counted (ProblemReason::counted()) and how far the stage got.
 * A reason that is not only counted is a row of import_problems, naming the thing.
 */
final class Run
{
    /** The kinds of thing an import counts, as its report shows them. */
    public const KINDS = ['customers', 'balances', 'groups', 'servers', 'plans', 'categories', 'services', 'discount_codes', 'gift_codes', 'channels', 'cards'];

    /** Where `counts` keeps the reasons only counted, by reason. */
    private const REASONS = 'reasons';

    /** Where `counts` keeps how many things the stage under way went through. */
    private const STAGE = 'stage';

    /** Where `counts` keeps, by server, how many steps in a row found its panel silent (silences()). */
    private const SILENT = 'silent';

    private const KEY_MAX = 191;
    private const DETAIL_MAX = 500;

    /** @var array<string, array<string, int>> */
    private array $counts;

    /** @var list<array<string, mixed>> Problem rows not written yet (problems()) */
    private array $problems = [];

    /** @var array<string, Server|null> The server each panel maps to, once asked */
    private array $servers = [];

    /** @var array<string, array<string, mixed>>|null The panels' connections, once decrypted */
    private ?array $connections = null;

    public function __construct(
        public readonly Import $import,
        public readonly Choices $choices,
        /** The other bot's name as the owner reads it (its migrator's label): «موجودی منتقل‌شده از …». */
        public readonly string $label,
        private readonly Links $links,
        private readonly Encrypter $encrypter,
    ) {
        $this->counts = $import->counts ?? [];
    }

    /**
     * Whether an earlier import of this bot brought the thing already (its `$link` kind): then nothing of it is written
     * again — counted as linked while its row is there, and as removed once the owner deleted it (never brought back).
     *
     * @param class-string<Model> $model
     */
    public function broughtBefore(string $link, string $kind, string $key, string $model): bool
    {
        if (!$this->links->brought($this->import, $link, $key)) {
            return false;
        }
        if ($this->links->row($this->import, $link, $key, $model) !== null) {
            $this->linked($kind);
        } else {
            $this->skipped($kind, $key, ProblemReason::Removed);
        }

        return true;
    }

    /** One of `$kind` made in the shop. */
    public function created(string $kind): void
    {
        $this->count($kind, 'created');
    }

    /** One of `$kind` found in the shop already — made by an earlier import, or the shop's own — and linked to it. */
    public function linked(string $kind): void
    {
        $this->count($kind, 'linked');
    }

    /** One of `$kind` not brought, and why (problem()). */
    public function skipped(string $kind, string $key, ProblemReason $reason, ?string $detail = null): void
    {
        $this->count($kind, 'skipped');
        $this->problem($kind, $key, $reason, $detail);
    }

    /**
     * Why something did not come as it was: counted, or a row naming it (`$key`, and what finds it in `$detail`) — kept
     * with the import's cursor (problems()), so a step cut short never names a thing twice.
     */
    public function problem(string $kind, string $key, ProblemReason $reason, ?string $detail = null): void
    {
        if ($reason->counted()) {
            $this->counts[self::REASONS][$reason->value] = ($this->counts[self::REASONS][$reason->value] ?? 0) + 1;

            return;
        }
        $this->problems[] = [
            'import_id' => $this->import->id,
            'kind' => $kind,
            'source_key' => Text::fit($key, self::KEY_MAX),
            'reason' => $reason->value,
            'detail' => $detail === null || trim($detail) === '' ? null : Text::fit(trim($detail), self::DETAIL_MAX),
            'created_at' => now(),
        ];
    }

    /**
     * The problem rows found since the last call, for the import's next save to write with its cursor.
     *
     * @return list<array<string, mixed>>
     */
    public function problems(): array
    {
        $problems = $this->problems;
        $this->problems = [];

        return $problems;
    }

    /** The stage under way went through one more thing. */
    public function advance(): void
    {
        $this->count(self::STAGE, 'done');
    }

    /** A new stage begins: none gone through yet. */
    public function restart(): void
    {
        $this->counts[self::STAGE] = ['done' => 0];
    }

    /** @return array<string, array<string, int>> The tally as the import keeps it (`counts`). */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return array<string, mixed>|null A panel of the survey, by its key */
    private function panel(string $key): ?array
    {
        foreach ($this->panels() as $panel) {
            if ((string) ($panel['key'] ?? '') === $key) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * The panels of the survey (Survey::toArray()'s), each with its key, name, type, url, driver and whether it is addable.
     *
     * @return list<array<string, mixed>>
     */
    public function panels(): array
    {
        $panels = $this->import->survey['panels'] ?? [];

        return array_values(array_filter(is_array($panels) ? $panels : [], 'is_array'));
    }

    /**
     * A panel's connection as its connector's server form takes it, out of the import's encrypted copy (`panels`).
     *
     * @return array<string, mixed>
     */
    public function connection(string $key): array
    {
        if ($this->connections === null) {
            $decoded = $this->import->panels === null ? null : json_decode($this->encrypter->decrypt($this->import->panels), true);
            $this->connections = is_array($decoded) ? array_filter($decoded, 'is_array') : [];
        }
        $connection = $this->connections[$key] ?? [];

        return is_array($connection) ? $connection : [];
    }

    /**
     * The shop's server a panel's services are on: the one the owner chose, or the one the panel was added as (a server
     * link) — null when it is skipped, was not added, or its server is gone since.
     */
    public function server(string $panel): ?Server
    {
        if (!array_key_exists($panel, $this->servers)) {
            $id = match ($this->choices->action($panel)) {
                Choices::SERVER => $this->choices->serverId($panel),
                Choices::ADD => $this->links->alive($this->import, Links::SERVER, self::serverKey($this->panel($panel) ?? ['key' => $panel]), Server::class),
                default => null,
            };
            $this->servers[$panel] = $id === null ? null : Server::query()->find($id);
        }

        return $this->servers[$panel];
    }

    /**
     * What a server added of a panel is linked by: the panel's address (Choices::address()), or — a panel without one —
     * its key.
     *
     * @param array<string, mixed> $panel A panel of the survey
     */
    public static function serverKey(array $panel): string
    {
        return Choices::address(is_string($panel['url'] ?? null) ? $panel['url'] : null) ?? 'key:' . (string) ($panel['key'] ?? '');
    }

    /** How many steps found the server's panel silent in a row (ServiceWriter), before its services are given up on. */
    public function silences(int $server): int
    {
        return (int) ($this->counts[self::SILENT]["server:{$server}"] ?? 0);
    }

    /** The server's panel was silent once more; answering again, it is not (`$answered`). */
    public function silent(int $server, bool $answered = false): void
    {
        if ($answered) {
            unset($this->counts[self::SILENT]["server:{$server}"]);

            return;
        }
        $this->counts[self::SILENT]["server:{$server}"] = $this->silences($server) + 1;
    }

    /**
     * Every server the survey's panels map to, each once, in the panels' order: where a plan sold on every panel goes.
     *
     * @return list<Server>
     */
    public function servers(): array
    {
        $servers = [];
        foreach ($this->panels() as $panel) {
            $server = $this->server((string) ($panel['key'] ?? ''));
            if ($server !== null) {
                $servers[$server->id] = $server;
            }
        }

        return array_values($servers);
    }

    /**
     * The kinds' tally as the report shows it — every kind, from zero —; null before the import writes anything.
     *
     * @param array<string, array<string, int>>|null $counts The import's `counts`
     * @return array<string, array{created: int, linked: int, skipped: int}>|null
     */
    public static function tally(?array $counts): ?array
    {
        if ($counts === null) {
            return null;
        }
        $tally = [];
        foreach (self::KINDS as $kind) {
            $tally[$kind] = ['created' => (int) ($counts[$kind]['created'] ?? 0), 'linked' => (int) ($counts[$kind]['linked'] ?? 0), 'skipped' => (int) ($counts[$kind]['skipped'] ?? 0)];
        }

        return $tally;
    }

    /**
     * The reasons only counted, by reason.
     *
     * @param array<string, array<string, int>>|null $counts The import's `counts`
     * @return array<string, int>
     */
    public static function reasons(?array $counts): array
    {
        return array_map('intval', $counts[self::REASONS] ?? []);
    }

    /**
     * How many things the stage under way went through.
     *
     * @param array<string, array<string, int>>|null $counts The import's `counts`
     */
    public static function done(?array $counts): int
    {
        return (int) ($counts[self::STAGE]['done'] ?? 0);
    }

    /** A refusal of the shop's for a problem's detail: every message it carries, in order. */
    public static function words(DomainRuleException $e): string
    {
        $messages = array_values(array_unique(array_merge(...array_values($e->errors()))));

        return $messages === [] ? $e->getMessage() : implode(' ', $messages);
    }

    /** Latin words (a client's name, a code) set apart inside a Persian line, so it reads in its order. */
    public static function isolate(string $text): string
    {
        return "\u{2068}{$text}\u{2069}";
    }

    private function count(string $kind, string $what): void
    {
        $this->counts[$kind][$what] = ($this->counts[$kind][$what] ?? 0) + 1;
    }
}
