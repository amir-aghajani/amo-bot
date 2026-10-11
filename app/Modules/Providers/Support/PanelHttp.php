<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * How a connector talks to its panel: the shop's outgoing HTTP client (the container's — a test swaps its transport)
 * with the one request shape every panel gets, the log a connector notes its sign-ins in, and the readings of an answer
 * that is not the panel's API that every connector shares. Handed to each panel client as its connector builds it
 * (PanelDriver::connect()).
 */
final class PanelHttp
{
    public function __construct(
        private readonly ClientInterface $client,
        public readonly LoggerInterface $logger,
    ) {}

    /**
     * One request to the panel at `$connection`: its address plus `$path`, its TLS rule and timeouts. The answer comes
     * back whatever its status (the connector reads it) and a redirect is never followed (the connector reports it: a
     * wrong address).
     *
     * @param array<string, mixed> $options Guzzle request options (headers, body)
     * @throws ConnectionException on a transport failure
     */
    public function send(PanelConnection $connection, string $method, string $path, array $options = []): ResponseInterface
    {
        try {
            return $this->client->request($method, $connection->baseUrl . $path, $options + $connection->requestOptions() + [
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException $e) {
            throw ConnectionException::fromTransport($e);
        }
    }

    /**
     * Guzzle options with `$headers` added; a header the options set already gives way to these.
     *
     * @param array<string, mixed> $options
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function withHeaders(array $options, array $headers): array
    {
        $options['headers'] = $headers + (array) ($options['headers'] ?? []);

        return $options;
    }

    public static function isRedirect(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 300 && $response->getStatusCode() < 400;
    }

    /** A redirect where the API should have answered (http to https, another address), with the connector's hint. */
    public static function redirected(ResponseInterface $response, string $hint): UnexpectedResponseException
    {
        return new UnexpectedResponseException($response->getStatusCode(), location: $response->getHeaderLine('Location'), hint: $hint);
    }

    /**
     * An answer that is not the panel's API — an unknown route, a web page, a proxy's error — with the readable start of
     * its body for the owner's diagnosis and the connector's guess at why: `$unknownRoute` for a 404, `$notApi` for a
     * page that came back as a success.
     */
    public static function notTheApi(ResponseInterface $response, string $unknownRoute, string $notApi): UnexpectedResponseException
    {
        $status = $response->getStatusCode();
        $excerpt = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $response->getBody())) ?? ''), 0, 200);

        return new UnexpectedResponseException($status, $excerpt, hint: match (true) {
            $status === 404 => $unknownRoute,
            $status < 400 => $notApi,
            default => '',
        });
    }
}
