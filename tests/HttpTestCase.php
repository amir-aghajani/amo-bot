<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config\Repository as Config;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Agency\Services\AgentBots;
use App\Modules\Auth\NamedShop;
use App\Modules\Auth\Services\OwnerAuth;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
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
 * Sends PSR-7 requests straight into the Slim app. The session is the CLI's in-memory one, so a sign-in lasts the test;
 * openShop() names the owner's shop and bearer() carries a website customer's token on the requests that follow. The
 * shop counts as installed. Both sides of every API call are validated against resources/api/openapi.yaml (with the
 * website admins' paths, ApiDescription): the request on its way in (unless unchecked()) and the answer on its way back.
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

    /** The API description with the website admins' paths (ApiDescription), read once a run. */
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

    /** Set the owner's panel login in the configuration; null for none. */
    protected function configureAdmin(?string $username = self::ADMIN_USERNAME, string $password = self::ADMIN_PASSWORD): void
    {
        $config = $this->service(Config::class);
        $config->set('admin.username', $username ?? '');
        $config->set('admin.password', $username === null ? '' : password_hash($password, PASSWORD_DEFAULT, ['cost' => 4]));
    }

    /** Open the owner's panel session directly, for a test that is not about signing in. */
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

    /** Make the requests that follow to /api/admin name this bot's shop (`X-Shop`), as a panel tab does. */
    protected function openShop(Bot $bot): void
    {
        $this->shop = $bot->id;
    }

    /**
     * Sign `$user` in as one of the shop's admins on its website: made an admin, the website letting admins in, a fresh
     * strong (Telegram) session whose token the requests that follow carry. Grants are the test's to set.
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
     * The website's customer buys `$plan` on `$server` with `$method` (`POST /orders`), the request's other fields
     * `$fields` beside them (a discount code, a service's name), under the Idempotency-Key `$key`.
     *
     * @param array<string, mixed> $fields
     */
    protected function placeOrder(Website $website, Plan $plan, Server $server, PaymentMethod $method, array $fields = [], string $key = 'key-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($website, '/orders'), ['plan_id' => $plan->id, 'server_id' => $server->id, 'method_id' => $method->id] + $fields, ['Idempotency-Key' => $key]);
    }

    /**
     * The website's customer tops their wallet up by `$amount` with `$method` (`POST /wallet/top-up`), under the
     * Idempotency-Key `$key`.
     */
    protected function placeTopUp(Website $website, int|string $amount, PaymentMethod $method, string $key = 'top-up-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($website, '/wallet/top-up'), ['amount' => $amount, 'method_id' => $method->id], ['Idempotency-Key' => $key]);
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
     * Send the next request without validating it, for a test of a request no panel sends and its refusal. Its answer
     * is still validated, and a request that does match the description fails the test.
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
     * A call as the panels make it: JSON, and the X-Requested-With header the CSRF guard asks for (send() is the bare
     * request).
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
     * POST one file as a multipart upload (`$field`) with the form's other `$fields`, as a browser form does.
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

    /** Add what the requests carry (openShop()'s `X-Shop` on /api/admin, bearer()'s token). */
    private function carrying(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->shop !== null && str_starts_with($request->getUri()->getPath(), '/api/admin/')) {
            $request = $request->withHeader(NamedShop::HEADER, (string) $this->shop);
        }

        return $this->bearer === null ? $request : $request->withHeader('Authorization', "Bearer {$this->bearer}");
    }

    /**
     * Handle the request, validating both ways against its operation: the request (unless unchecked()) and the answer's
     * status, content type and every field (objects are closed). A route the description lacks fails, unless it is no
     * route at all (a 404 or 405).
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
     * Validate a request against its operation (no body where it describes none); with `$unchecked`, require that it
     * does NOT match.
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

    /**
     * The answer refused the request with `$words` alone under `$field` — a 422 unless another status is given; a failure
     * says `$message` (the case), then the answer.
     */
    protected function assertRefusedUnder(string $field, string $words, ResponseInterface $response, int $status = 422, string $message = ''): void
    {
        $body = (string) $response->getBody();

        self::assertSame([$status, [$words]], [$response->getStatusCode(), $this->decode($response)['errors'][$field] ?? null], $message === '' ? $body : "{$message}: {$body}");
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
