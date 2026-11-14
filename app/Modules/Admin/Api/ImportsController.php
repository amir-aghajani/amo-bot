<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Core\Http\LongRunning;
use App\Core\Session\Session;
use App\Modules\Auth\Principal;
use App\Modules\Backups\Backups;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportProblem;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «انتقال از ربات دیگر», the owner's, always into the main shop: another bot's data read from its backup file or its
 * database, a step a request while the page is open; reviewed — what to bring, each of its panels mapped to a server —,
 * then brought in, a step a request again; its report kept with the problems it met. One import at a time.
 */
final class ImportsController extends ApiController
{
    /**
     * Seconds a request may work. A step stops once Imports::STEP_SECONDS are spent, but finishes the row it is on — a
     * several-MB INSERT staged, a panel slow to list its clients —; an upload kept or a database tried takes its own.
     */
    private const TIME_LIMIT = 5 * 60;

    /** The problems a page of the report lists. */
    private const PROBLEMS_PER_PAGE = 50;

    /** The fields of a connection to the other bot's database, read by the source (Sources\Stager). */
    private const CONNECTION = ['host', 'port', 'database', 'username', 'password'];

    /** A save whose `choices` is not an object: nothing a screen sends. */
    public const NO_CHOICES = 'انتخاب‌ها خوانده نشد؛ صفحه را دوباره باز کنید.';

    public function __construct(
        private readonly Imports $imports,
        private readonly Session $session,
    ) {}

    /** GET /imports — the bots an import reads, the one under way, the latest that ended */
    public function index(Request $request, Response $response): Response
    {
        $current = $this->imports->current();

        return $this->json($response, [
            'migrators' => array_map(static fn(Migrator $migrator): array => $migrator->describe()->toArray(), $this->imports->migrators()),
            'current' => $current === null ? null : $this->imports->present($current),
            'history' => array_map($this->imports->present(...), $this->imports->history()),
            // A backup file larger than the host takes is refused before it is sent.
            'upload_max_bytes' => Backups::uploadLimit(),
        ]);
    }

    /** POST /imports — multipart `migrator`, `file`: the other bot's backup, kept to be read */
    public function store(Request $request, Response $response): Response
    {
        $this->settle();
        $import = $this->imports->fromBackup(Input::text($this->input($request), 'migrator'), $this->uploadedFile($request), Principal::of($request)->actor()->reviewer);

        return $this->answer($response, $import, 201);
    }

    /** POST /imports/database — {migrator, host, port, database, username, password}: the connection tried at once */
    public function database(Request $request, Response $response): Response
    {
        $this->settle();
        $input = $this->input($request);
        $import = $this->imports->fromDatabase(Input::text($input, 'migrator'), array_intersect_key($input, array_flip(self::CONNECTION)), Principal::of($request)->actor()->reviewer);

        return $this->answer($response, $import, 201);
    }

    /**
     * GET /imports/{id}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->answer($response, $this->import($args));
    }

    /**
     * POST /imports/{id}/continue — one step of reading or writing, unless another is already on it (`stepped` false: the
     * page waits a moment before asking again); the page calls it until the phase changes
     *
     * @param array<string, string> $args
     */
    public function step(Request $request, Response $response, array $args): Response
    {
        $this->settle();
        $import = $this->import($args);
        $stepped = $this->imports->advance($import, Budget::seconds(Imports::STEP_SECONDS));

        return $this->json($response, ['import' => $this->imports->present($import), 'stepped' => $stepped]);
    }

    /**
     * PUT /imports/{id}/choices — {choices}: what to bring and where each panel's services go, saved before it starts
     *
     * @param array<string, string> $args
     */
    public function choose(Request $request, Response $response, array $args): Response
    {
        $choices = $this->input($request)['choices'] ?? null;
        if (!is_array($choices)) {
            throw ImportRefusedException::on('choices', self::NO_CHOICES);
        }

        return $this->answer($response, $this->imports->choose($this->import($args), $choices));
    }

    /**
     * POST /imports/{id}/start — from the review to writing; the page then calls continue
     *
     * @param array<string, string> $args
     */
    public function start(Request $request, Response $response, array $args): Response
    {
        return $this->answer($response, $this->imports->start($this->import($args)));
    }

    /**
     * DELETE /imports/{id} — an open import cancelled, its files and staged rows gone; an ended one's record deleted
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        // Begun, it ends — a large import's staged rows take a while to go — even when the owner closes the page.
        $this->settle();
        $this->imports->cancel($this->import($args));

        return $this->noContent($response);
    }

    /**
     * GET /imports/{id}/problems?reason=&kind=&page= — what it could not bring, a page at a time
     *
     * @param array<string, string> $args
     */
    public function problems(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams();
        $page = max(1, Input::integer($query, 'page') ?? 1);
        $found = $this->imports->problems($this->import($args), self::filter($query, 'reason'), self::filter($query, 'kind'), $page, self::PROBLEMS_PER_PAGE);

        return $this->json($response, [
            'problems' => array_map(static fn(ImportProblem $problem): array => [
                'kind' => $problem->kind,
                'source_key' => $problem->source_key,
                'reason' => $problem->reason->value,
                'label' => $problem->reason->label(),
                'detail' => $problem->detail,
                'created_at' => $problem->created_at?->toIso8601String(),
            ], $found['problems']),
            'total' => $found['total'],
            'page' => $page,
            'per_page' => self::PROBLEMS_PER_PAGE,
        ]);
    }

    /**
     * The import the route names; a 404 for none.
     *
     * @param array<string, string> $args
     */
    private function import(array $args): Import
    {
        return $this->imports->find($this->routeId($args));
    }

    private function answer(Response $response, Import $import, int $status = 200): Response
    {
        return $this->json($response, ['import' => $this->imports->present($import)], $status);
    }

    /**
     * A list filter off the query string; null for none.
     *
     * @param array<string, mixed> $query
     */
    private static function filter(array $query, string $field): ?string
    {
        $value = Input::text($query, $field);

        return $value === '' ? null : $value;
    }

    /**
     * Release the session — the panel's other requests need not wait on a step — and keep going if the owner hangs up,
     * so no step is left half-way.
     */
    private function settle(): void
    {
        $this->session->release();
        LongRunning::keepGoing(self::TIME_LIMIT);
    }
}
