<?php

declare(strict_types=1);

namespace App\Core\Http;

use Psr\Http\Message\ResponseInterface as Response;

/** Writes every JSON body: unescaped Unicode (the payloads are Persian), and one error shape. */
final class Json
{
    /**
     * Invalid UTF-8 becomes U+FFFD rather than failing the answer; anything else that cannot be encoded throws — never
     * an empty body under a 200.
     */
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function respond(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, self::FLAGS));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * The error shape: {"message", "errors"?: {field: [messages]}, "request_id", "debug"?}. `request_id` is the
     * request's (shown by the panels, carried by its log lines); `debug` comes from ErrorHandler::debug().
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
