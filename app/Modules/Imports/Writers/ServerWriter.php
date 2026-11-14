<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Providers\Services\ServerService;
use App\Support\Text;
use Illuminate\Database\ConnectionInterface;

/**
 * The servers stage: each panel of the other bot the owner chose to add becomes a server of the shop, through the
 * servers' own form and first check (ServerService), named as the other bot named it, switched off when the other bot
 * had closed it to new sales, with its capacity. One the form refuses, or whose panel does not answer, is not kept — its
 * services stay behind (panel_skipped). Linked by the panel's address (Run::serverKey()): a later import finds it again
 * whatever the panel is called — and, deleted since, adds it again: the owner chose «افزودن» for it in this import.
 */
final class ServerWriter
{
    private const KIND = 'servers';

    /** The longest server name the shop keeps. */
    private const NAME_MAX = 100;

    public function __construct(
        private readonly ServerService $servers,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * What an import keeps of a panel to add it as a server (imports.panels, encrypted): its connector's connection, and
     * whether it sells and how much — the server form's own switch and capacity.
     *
     * @return array<string, mixed>
     */
    public static function kept(ImportedPanel $panel): array
    {
        return ['is_active' => $panel->active, 'capacity' => $panel->capacity] + $panel->connection;
    }

    /** @param array<string, mixed> $panel A panel of the survey (Survey::toArray()) */
    public function write(Run $run, array $panel): void
    {
        $key = (string) ($panel['key'] ?? '');
        if ($run->choices->action($key) !== Choices::ADD) {
            return;
        }
        $link = Run::serverKey($panel);
        if ($this->links->alive($run->import, Links::SERVER, $link, Server::class) !== null) {
            $run->linked(self::KIND);

            return;
        }

        $name = Text::fit(trim((string) ($panel['name'] ?? '')) !== '' ? trim((string) $panel['name']) : $key, self::NAME_MAX);
        try {
            // The server's own fields around the connector's: its name, and switched on as a new server is unless kept off.
            $server = $this->db->transaction(function () use ($run, $panel, $key, $name, $link): Server {
                $server = $this->servers->create(['driver' => $panel['driver'] ?? '', 'name' => $name] + $run->connection($key) + ['is_active' => true]);
                $this->links->add($run->import, Links::SERVER, $link, $server->id);

                return $server;
            });
        } catch (ValidationException $e) {
            $run->skipped(self::KIND, $key, ProblemReason::ServerNotAdded, Run::words($e));

            return;
        }

        // Asked after the commit: a panel's answer may take its time, no transaction waits on it.
        $probe = $this->servers->check($server);
        if (!$probe['ok']) {
            // Not answering, it would only be a server nothing can be sold on: the owner adds it once its panel answers.
            $this->db->transaction(function () use ($run, $server, $link): void {
                $this->servers->delete($server);
                $this->links->drop($run->import, Links::SERVER, $link);
            });
            $run->skipped(self::KIND, $key, ProblemReason::ServerNotAdded, ProviderErrorPresenter::join((string) $probe['error'], (string) $probe['error_detail']));

            return;
        }
        $run->created(self::KIND);
    }
}
