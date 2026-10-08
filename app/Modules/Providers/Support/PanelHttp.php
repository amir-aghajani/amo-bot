<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

use App\Modules\Providers\Exceptions\ConnectionException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * How a driver talks to its panel: the shop's outgoing HTTP client (the container's — a test swaps its transport) with
 * the one request shape every panel gets, and the log a driver notes its sessions in. Handed to every panel's client as
 * its connector builds it (PanelDriver::connect()).
 */
final class PanelHttp
{
    public function __construct(
        private readonly ClientInterface $client,
        public readonly LoggerInterface $logger,
    ) {}

    /**
     * One request to the panel at `$connection`: its address and `$path`, its TLS rule and timeouts, the answer whatever
     * its status (the driver reads it), a redirect never followed (the driver reports it: a wrong address). A transport
     * failure is a ConnectionException.
     *
     * @param array<string, mixed> $options Guzzle request options (headers, body)
     * @throws ConnectionException
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

    /** The readable start of an answer that was not the panel's API — a web page, a proxy's error —, for the owner's diagnosis. */
    public static function excerpt(ResponseInterface $response): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $response->getBody())) ?? ''), 0, 200);
    }
}
