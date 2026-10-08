<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * A web origin as a browser sends it (`Origin`) and as CORS compares it: `scheme://host[:port]` — http or https, lower
 * case, the scheme's own port left out, nothing after it. What a website's owner types (an origin, perhaps with a
 * trailing slash) and what the browser sends are compared in this one form.
 */
final class Origin
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** The longest origin taken: a host is at most 253 characters, with its scheme and port around it. */
    private const MAX = 255;

    /** The longest address read for its origin (a page's, a sign-in's return address). */
    private const MAX_ADDRESS = 2048;

    /** `$value` as an origin — `scheme://host[:port]` alone, a trailing "/" tolerated —; null for anything else (a path, a query). */
    public static function normalize(string $value): ?string
    {
        $parts = self::parts(rtrim(trim($value), '/'));
        if ($parts === null || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return self::build($parts);
    }

    /** The origin of an http(s) address — a page of the site, a path and all —; null for what is no such address. */
    public static function of(string $url): ?string
    {
        $parts = self::parts(trim($url));

        return $parts === null ? null : self::build($parts);
    }

    /**
     * An http(s) address's parts, with a host and no credentials; null for anything else.
     *
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string}|null
     */
    private static function parts(string $value): ?array
    {
        if ($value === '' || strlen($value) > self::MAX_ADDRESS || preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!isset(self::DEFAULT_PORTS[$scheme]) || $host === '') {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host] + array_intersect_key($parts, array_flip(['port', 'path', 'query', 'fragment']));
    }

    /** @param array{scheme: string, host: string, port?: int} $parts */
    private static function build(array $parts): ?string
    {
        $port = $parts['port'] ?? null;
        $origin = $parts['scheme'] . '://' . $parts['host'] . ($port === null || $port === self::DEFAULT_PORTS[$parts['scheme']] ? '' : ':' . $port);

        return strlen($origin) <= self::MAX ? $origin : null;
    }
}
