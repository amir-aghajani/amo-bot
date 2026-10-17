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
 * credentials, its guard against an empty one and what to make of a refusal — the gateway's own words in it cut short
 * alike (said()).
 */
final class GatewayHttp
{
    /** The most of a gateway's own words a log line keeps. */
    private const SAID_MAX = 200;

    /** A gateway's own words for a log line, cut short; '' for none (anything but text). */
    public static function said(mixed $words): string
    {
        return is_string($words) ? mb_substr($words, 0, self::SAID_MAX) : '';
    }

    /**
     * @param string $name The gateway as the log names it: "<name> is out of reach"
     * @param array<string, mixed> $options The request's own: `json`, `query`
     * @param array<string, string> $headers Its credentials
     * @param string|null $hide A credential the address itself carries (a gateway that takes its key in the query), kept
     *                          out of the message of a call that did not get through
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach
     */
    public static function call(ClientInterface $http, string $name, string $method, string $url, array $options = [], array $headers = [], ?string $hide = null): array
    {
        try {
            $response = $http->request($method, $url, [
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => $headers + ['Accept' => 'application/json'],
            ] + $options);
        } catch (GuzzleException $e) {
            $said = $hide === null || $hide === '' ? $e->getMessage() : str_replace([$hide, rawurlencode($hide)], '***', $e->getMessage());

            throw new GatewayUnavailableException("{$name} is out of reach: {$said}", $e);
        }
        $answer = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($answer) && !array_is_list($answer) ? $answer : []];
    }
}
