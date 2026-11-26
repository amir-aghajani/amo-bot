<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Door;

use App\Core\Application;
use App\Core\Session\Session;
use App\Modules\Telegram\Admin\AdminSeat;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * The panels' API as one seat in the bot uses it (for()): each request is the panel's very route, under the seat's door
 * (BotAdminDoor), handled in-process by the app's own HTTP kernel — the same validation, refusals and log lines as the
 * panels, the answers the same JSON. A 2xx answers its body; anything else is an ApiRefusal with the panel's
 * Persian words. Paths are the panels' (`/plans`, `/payments/12/approve`), without /api/admin.
 */
final class PanelBridge
{
    public function __construct(
        private readonly Application $app,
        private readonly ?AdminSeat $seat = null,
    ) {}

    /** The API as `$seat` uses it. */
    public function for(AdminSeat $seat): self
    {
        return new self($this->app, $seat);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function get(string $path, array $query = []): array
    {
        return $this->json('GET', $path . self::query($query));
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function post(string $path, ?array $body = null, array $headers = []): array
    {
        return $this->json('POST', $path, $body ?? [], $headers);
    }

    /**
     * @param array<string, mixed>|list<mixed> $body
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function put(string $path, array $body): array
    {
        return $this->json('PUT', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function patch(string $path, array $body): array
    {
        return $this->json('PATCH', $path, $body);
    }

    /**
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function delete(string $path): array
    {
        return $this->json('DELETE', $path);
    }

    /**
     * POST one file as a multipart upload under `$field`, with the form's other fields — as the panel's forms send a
     * picture or a backup.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function upload(string $path, string $field, string $filename, string $bytes, string $mime, array $fields = [], array $headers = []): array
    {
        $request = $this->request('POST', $path, $headers)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=amobot-bot')
            ->withUploadedFiles([$field => new UploadedFile((new StreamFactory())->createStream($bytes), $filename, $mime, strlen($bytes))]);
        if ($fields !== []) {
            $request = $request->withParsedBody($fields);
        }

        return self::decode($this->send($request));
    }

    /**
     * A file a route answers (a receipt's picture, a backup): its bytes, type and name.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     * @throws ApiRefusal
     */
    public function file(string $path, array $query = []): ApiFile
    {
        $response = $this->send($this->request('GET', $path . self::query($query)));
        if ($response->getStatusCode() >= 300) {
            self::decode($response);
        }
        $mime = strtok($response->getHeaderLine('Content-Type'), ';') ?: 'application/octet-stream';
        $name = preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $response->getHeaderLine('Content-Disposition'), $match) === 1 ? rawurldecode($match[1]) : basename($path);

        return new ApiFile((string) $response->getBody(), trim($mime), $name);
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    private function json(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $request = $this->request($method, $path, $headers);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)));
        }

        return self::decode($this->send($request));
    }

    /**
     * The request to `$path` under the seat's door, with `$headers` (an idempotency key).
     *
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $seat = $this->seat ?? throw new \LogicException('The panels\' API is asked for one seat: PanelBridge::for().');
        $base = rtrim((string) $this->app->container()->get('http.base_path'), '/');
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://bot.internal' . $base . BotAdminDoor::PATH . '/' . $seat->kind->value . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withAttribute(BotAdminDoor::SEAT, $seat);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /** Served in-process, the session held again after it: a controller that lets it go (an update's step) ends no more. */
    private function send(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app->container()->get(Session::class)->within(fn(): ResponseInterface => $this->app->http()->handle($request));
    }

    /**
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    private static function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $body = $raw === '' ? [] : json_decode($raw, true);
        $status = $response->getStatusCode();
        if ($status < 300) {
            return is_array($body) ? $body : [];
        }
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : 'کار انجام نشد؛ دوباره امتحان کنید.';
        $errors = [];
        foreach (is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : [] as $field => $messages) {
            $errors[(string) $field] = array_values(array_filter(is_array($messages) ? $messages : [$messages], is_string(...)));
        }

        throw new ApiRefusal($status, $message, $errors);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     */
    private static function query(array $query): string
    {
        $query = array_filter($query, static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return $query === [] ? '' : '?' . http_build_query(array_map(static fn(mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $query), '', '&', PHP_QUERY_RFC3986);
    }
}
