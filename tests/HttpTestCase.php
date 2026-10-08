<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config\Repository as Config;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Agency\Services\AgentBots;
use App\Modules\Auth\NamedShop;
use App\Modules\Auth\Services\OwnerAuth;
use App\Modules\Bots\Models\Bot;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use cebe\openapi\spec\OpenApi;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\PathFinder;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\RoutedServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\ApiDescription;

/**
 * Sends PSR-7 requests straight into the Slim app (no web server). The session is the in-memory
 * CLI store, so login state carries across requests inside one test and is reset between tests;
 * the shop the owner's panel works in is the one its requests that follow name (openShop()), and a
 * website's customer the bearer token they carry (bearer()) — one of the shop's admins on its website
 * too (loginAsStaff()).
 * The shop under test counts as installed (its lock is the run's own, never this machine's
 * storage/), and its sign-in attempts start from none. Both sides of every API call are held to
 * resources/api/openapi.yaml, the contract the panels' types are generated from — as Support\ApiDescription
 * reads it, the website's admins' paths added —: the request on its way in — its path, query and body
 * (unchecked() lets one malformed request through, for a test of its refusal) — and the answer on its
 * way back.
 */
abstract class HttpTestCase extends DatabaseTestCase
{
    public const ADMIN_USERNAME = 'root';
    public const ADMIN_PASSWORD = 'secret123';

    private static ?RoutedServerRequestValidator $requests = null;
    private static ?ResponseValidator $responses = null;

    /** @var array<string, OperationAddress|null> The operation each method and path is, as the description has it */
    private static array $operations = [];

    /** The next request goes out without being held to the description (unchecked()). */
    private bool $unchecked = false;

    /** The website's customer the requests that follow are made for: their bearer token (bearer()). */
    private ?string $bearer = null;

    /** The shop the owner's panel's requests that follow name (openShop()); null: none, the main bot's. */
    private ?int $shop = null;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $this->installed(true);
        $this->configureAdmin();
    }

    protected function tearDown(): void
    {
        $this->configureAdmin(null);

        parent::tearDown();
    }

    /** resources/api/openapi.yaml, the website's admins' paths added (Support\ApiDescription): read once a run for every test that checks against it. */
    public static function apiDescription(): OpenApi
    {
        return ApiDescription::openApi();
    }

    /** Play an installed shop (the default) or a fresh one for the rest of the test: its lock written, or not there. */
    protected function installed(bool $installed): void
    {
        $lock = (string) $this->app()->container()->get('installation.lock');
        if ($installed) {
            touch($lock);
        } elseif (is_file($lock)) {
            unlink($lock);
        }
    }

    /** Put the panel's login into config (what config.php would hold); null = no login configured. */
    protected function configureAdmin(?string $username = self::ADMIN_USERNAME, string $password = self::ADMIN_PASSWORD): void
    {
        $config = $this->service(Config::class);
        $config->set('admin.username', $username ?? '');
        $config->set('admin.password', $username === null ? '' : password_hash($password, PASSWORD_DEFAULT, ['cost' => 4]));
    }

    /**
     * Open the owner's session (/api/admin) — the login config.php holds (configureAdmin()) —, as signing in does: for a
     * test that is not about signing in.
     */
    protected function loginAsAdmin(): void
    {
        $username = (string) $this->service(Config::class)->get('admin.username');
        $signIn = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/admin/auth/login');
        $this->service(OwnerAuth::class)->attempt($username, self::ADMIN_PASSWORD, $signIn) ?? throw new \RuntimeException('Admin login failed in test setup.');
    }

    /** Sign in to the agent panel (/api/agent) as the agent who owns the bot, with a fresh login link of theirs. */
    protected function loginAsAgent(Bot $bot): void
    {
        $response = $this->postJson('/api/agent/auth/link', ['code' => $this->loginCode($bot)]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Agent login failed in test setup: ' . (string) $response->getBody());
        }
    }

    /** The code of a fresh login link of the agent who owns the bot, as the main bot hands it to them (`#code=`). */
    protected function loginCode(Bot $bot): string
    {
        $agent = $bot->agent ?? throw new \LogicException("Bot #{$bot->id} has no agent.");
        parse_str((string) parse_url((string) $this->service(AgentBots::class)->loginLink($agent), PHP_URL_FRAGMENT), $fragment);

        return (string) ($fragment['code'] ?? '');
    }

    /**
     * The owner opens this bot's shop in their panel: the requests that follow to the owner's API (/api/admin) name it,
     * as the panel's tab names its shop on every request (`X-Shop`) — the main bot's named too.
     */
    protected function openShop(Bot $bot): void
    {
        $this->shop = $bot->id;
    }

    /**
     * One of the shop's admins signed in on its website as its admin API takes them (Store\Http\StaffMiddleware): the
     * customer made an admin of the shop, the website letting its admins in, and a session of theirs signed in with
     * Telegram (a strong sign-in) a moment ago (a recent one) — the requests that follow carry its token (bearer()). What
     * the website grants them is the test's.
     */
    protected function loginAsStaff(Website $website, User $user): void
    {
        $user->forceFill(['role' => UserRole::Admin])->save();
        $website->forceFill(['staff_enabled' => true])->save();
        $this->bearer($this->customerSession($user, ['method' => SignInMethod::Telegram]));
    }

    /** The address of a website's Store API endpoint: its base address, then `$path` ('' for its `GET /`). */
    protected function storeApi(Website $website, string $path = ''): string
    {
        return "/api/store/v1/{$website->key}{$path}";
    }

    /**
     * The requests that follow carry this customer's bearer token (`Authorization: Bearer …`), as their website sends
     * it; null: none.
     */
    protected function bearer(?string $token): void
    {
        $this->bearer = $token;
    }

    /**
     * Let the next request go out as it is, not held to the API description — for a test whose point is a request no
     * panel sends (a field of the wrong type, a required one missing, a value outside its enum, a field the operation
     * does not take) and the server's refusal of it. Its answer is still checked; a request that does match the
     * description fails the test, so the call never outlives its reason.
     */
    protected function unchecked(): static
    {
        $this->unchecked = true;

        return $this;
    }

    /** @param array<string, string> $headers */
    protected function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->send('GET', $path, null, $headers);
    }

    /** @param array<string, mixed>|null $body */
    protected function postJson(string $path, ?array $body = null): ResponseInterface
    {
        return $this->json('POST', $path, $body);
    }

    /** @param array<string, mixed>|null $body */
    protected function putJson(string $path, ?array $body = null): ResponseInterface
    {
        return $this->json('PUT', $path, $body);
    }

    /** @param array<string, mixed>|null $body */
    protected function patchJson(string $path, ?array $body = null): ResponseInterface
    {
        return $this->json('PATCH', $path, $body);
    }

    protected function deleteJson(string $path): ResponseInterface
    {
        return $this->json('DELETE', $path);
    }

    /**
     * A state-changing call as the SPA makes it: JSON in, and the X-Requested-With header the API's
     * CSRF guard asks for. `send()` is the bare request, for the test of that guard.
     *
     * @param array<string, mixed>|null $body
     */
    protected function json(string $method, string $path, ?array $body = null): ResponseInterface
    {
        return $this->send($method, $path, $body, ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    protected function send(string $method, string $path, ?array $json = null, array $headers = []): ResponseInterface
    {
        $request = $this->carrying((new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)
            ->withHeader('Accept', 'application/json'));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
        }

        return $this->handle($request);
    }

    /**
     * POST one file as a multipart upload (`$field`), the way a browser form does — with the form's other fields
     * (`$fields`, as PHP parses them), and a website customer's bearer token while the requests carry one (bearer()).
     *
     * @param array<string, string> $fields
     */
    protected function upload(string $path, string $field, string $filename, string $bytes, array $fields = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Content-Type', 'multipart/form-data; boundary=amobot-test')
            ->withUploadedFiles([$field => new UploadedFile((new StreamFactory())->createStream($bytes), $filename, null, strlen($bytes))]);
        if ($fields !== []) {
            $request = $request->withParsedBody($fields);
        }

        return $this->handle($this->carrying($request));
    }

    /**
     * What a request carries of the ones that follow (bearer(), openShop()): a website customer's bearer token, and the
     * shop the owner opened on a request of the owner's panel.
     */
    private function carrying(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->shop !== null && str_starts_with($request->getUri()->getPath(), '/api/admin/')) {
            $request = $request->withHeader(NamedShop::HEADER, (string) $this->shop);
        }

        return $this->bearer === null ? $request : $request->withHeader('Authorization', "Bearer {$this->bearer}");
    }

    /**
     * The request through the app, both ways held to what resources/api/openapi.yaml says of its operation: the request
     * before it goes in (unless the test let it through unchecked()), the answer as it comes back — its status, its
     * content type and every field of its JSON (objects there are closed: a field the description lacks fails too). A
     * route the description does not have fails, except an address that is nobody's route (a 404 or 405 is all it gets).
     */
    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        $unchecked = $this->unchecked;
        $this->unchecked = false;

        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/') && $path !== '/health') {
            return $this->app()->http()->handle($request);
        }

        $key = "{$request->getMethod()} {$path}";
        if (!array_key_exists($key, self::$operations)) {
            self::$operations[$key] = (new PathFinder(self::apiDescription(), $path, $request->getMethod()))->search()[0] ?? null;
        }
        $operation = self::$operations[$key];
        if ($operation !== null) {
            $this->checkRequest($operation, $request, $unchecked);
        }

        $response = $this->app()->http()->handle($request);
        $status = $response->getStatusCode();
        if ($operation === null) {
            if ($status !== 404 && $status !== 405) {
                self::fail(sprintf('%s %s answered %d but is not in the API description (%s).', $request->getMethod(), $path, $status, ApiDescription::FILE));
            }

            return $response;
        }

        self::$responses ??= (new ValidatorBuilder())->fromSchema(self::apiDescription())->getResponseValidator();
        try {
            self::$responses->validate($operation, $response);
        } catch (ValidationFailed $e) {
            self::fail(sprintf("%s %s answered %d, which does not match the API description: %s\n%s", $request->getMethod(), $path, $status, self::reason($e), (string) $response->getBody()));
        } finally {
            $response->getBody()->rewind();
        }

        return $response;
    }

    /**
     * A request as its operation takes it — path, query and body, and no body at all where the operation describes
     * none —, or, let through unchecked(), one that is not.
     */
    private function checkRequest(OperationAddress $operation, ServerRequestInterface $request, bool $unchecked): void
    {
        self::$requests ??= (new ValidatorBuilder())->fromSchema(self::apiDescription())->getRoutedRequestValidator();
        $problem = null;
        try {
            self::$requests->validate($operation, $request);
            if (!self::takesBody($operation) && self::carriesBody($request)) {
                $problem = 'the operation takes no body';
            }
        } catch (ValidationFailed $e) {
            $problem = self::reason($e);
        } finally {
            $request->getBody()->rewind();
        }

        $sent = sprintf('%s %s', $request->getMethod(), (string) $request->getUri());
        if ($unchecked && $problem === null) {
            self::fail("{$sent} was sent unchecked(), but it matches the API description: send it checked.");
        }
        if (!$unchecked && $problem !== null) {
            self::fail(sprintf("%s does not match the API description: %s\n%s", $sent, $problem, (string) $request->getBody()));
        }
    }

    /** Whether the operation describes a request body; one that does not (a GET, a DELETE, a POST marked x-no-body) takes none. */
    private static function takesBody(OperationAddress $operation): bool
    {
        $operations = self::apiDescription()->paths->getPath($operation->path())?->getOperations() ?? [];

        return ($operations[$operation->method()] ?? null)?->requestBody !== null;
    }

    /** Whether the request carries anything: a file, or a body with something in it (`{}` and `[]` hold nothing). */
    private static function carriesBody(ServerRequestInterface $request): bool
    {
        $body = trim((string) $request->getBody());

        return $request->getUploadedFiles() !== [] || ($body !== '' && json_decode($body, true) !== []);
    }

    /** What a validator found wrong: its finding, and the deepest reason under it with the field it is about. */
    private static function reason(ValidationFailed $e): string
    {
        $cause = $e;
        while ($cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }
        if ($cause === $e) {
            return $e->getMessage();
        }
        $where = $cause instanceof SchemaMismatch && $cause->dataBreadCrumb() !== null ? implode('.', $cause->dataBreadCrumb()->buildChain()) . ': ' : '';

        return "{$e->getMessage()} — {$where}{$cause->getMessage()}";
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
