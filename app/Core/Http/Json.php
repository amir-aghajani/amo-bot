<?php

declare(strict_types=1);

namespace App\Core\Http;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * The one place that writes JSON bodies, so every endpoint, middleware and error handler agrees
 * on encoding (unescaped Unicode — the payloads are Persian) and headers.
 */
final class Json
{
    /**
     * A byte that is not UTF-8 (a name a panel sent) becomes U+FFFD rather than failing the answer; anything else that
     * cannot be encoded is the code's mistake and throws — never an empty body under a 200.
     */
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function respond(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, self::FLAGS));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * The error shape shared by the API, its middleware and the error handler:
     * {"message": string, "errors"?: {field: [messages]}, "request_id": string, "debug"?: {...}} — `request_id` the
     * request's (RequestId: what the panel shows, and the log's lines of it carry), `debug` only for a failure of the
     * server's, with APP_DEBUG on, to a request from this machine (ErrorHandler::debug(): the exception's class, its
     * message, where it happened).
     *
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>|null $debug
     */
    public static function error(Response $response, string $message, int $status, array $errors = [], ?array $debug = null): Response
    {
        $payload = ['message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        $request = RequestId::current();
        if ($request !== null) {
            $payload['request_id'] = $request;
        }
        if ($debug !== null) {
            $payload['debug'] = $debug;
        }

        return self::respond($response, $payload, $status);
    }
}
