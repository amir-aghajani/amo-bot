<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers;

use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * One call to an online gateway's API, made as every online driver makes it: never following a redirect (an answer is
 * the gateway's own host's or none), asking for JSON, an error status read like any other — and back its HTTP status
 * and its JSON object, empty for anything else (a list, a page, nothing). Each driver brings its address, its
 * credentials, its guard against an empty one and what to make of a refusal.
 */
final class GatewayHttp
{
    /**
     * @param string $name The gateway as the log names it: "<name> is out of reach"
     * @param array<string, mixed> $options The request's own: `json`, `query`
     * @param array<string, string> $headers Its credentials
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach
     */
    public static function call(ClientInterface $http, string $name, string $method, string $url, array $options = [], array $headers = []): array
    {
        try {
            $response = $http->request($method, $url, [
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => $headers + ['Accept' => 'application/json'],
            ] + $options);
        } catch (GuzzleException $e) {
            throw new GatewayUnavailableException("{$name} is out of reach: " . $e->getMessage(), $e);
        }
        $answer = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($answer) && !array_is_list($answer) ? $answer : []];
    }
}
