<?php

declare(strict_types=1);

namespace App\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a request came from: the browser's address, and whether it arrived over HTTPS.
 *
 * Behind a proxy REMOTE_ADDR is the proxy's; the X-Forwarded-For chain is walked back from the connection only through
 * TRUSTED_PROXIES (addresses or CIDR ranges), since anyone can write that header and would get a fresh address for every
 * sign-in attempt. HTTPS is believed from anyone's forwarding headers: it only adds protection (HSTS, a secure cookie).
 */
final class RequestOrigin
{
    /** The bits of an IPv6 address a throttle counts its tries by: the /64 a host is handed at the least. */
    private const IPV6_NETWORK = 64;

    /**
     * Headers a proxy, a tunnel or a CDN in front of the shop adds, besides the X-Forwarded-* family: a request carrying
     * one was forwarded, whatever its connection says.
     */
    private const FORWARDING = ['forwarded', 'via', 'x-real-ip', 'client-ip', 'true-client-ip', 'cf-connecting-ip', 'cf-ray', 'fastly-client-ip', 'x-client-ip', 'x-cluster-client-ip', 'x-original-forwarded-for'];

    /** @param list<string> $trustedProxies Addresses and CIDR ranges, IPv4 or IPv6 */
    public function __construct(private readonly array $trustedProxies = []) {}

    /**
     * Whether the request came straight from this machine: a loopback connection with no forwarding header at all (a
     * local proxy or tunnel makes every visitor look local). Only such a request is shown APP_DEBUG's details.
     */
    public function fromThisMachine(ServerRequestInterface $request): bool
    {
        $packed = inet_pton((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $loopback = match (true) {
            $packed === false => false,
            strlen($packed) === 4 => $packed[0] === "\x7f",
            str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") => $packed[12] === "\x7f",
            default => $packed === str_repeat("\0", 15) . "\x01",
        };
        if (!$loopback) {
            return false;
        }

        foreach (array_keys($request->getHeaders()) as $name) {
            $name = strtolower((string) $name);
            if (str_starts_with($name, 'x-forwarded-') || in_array($name, self::FORWARDING, true)) {
                return false;
            }
        }

        return true;
    }

    /** TRUSTED_PROXIES as config.php holds it: addresses and ranges apart by commas or spaces. */
    public static function trusting(string $list): self
    {
        return new self(array_values(array_filter(preg_split('/[\s,]+/', $list) ?: [], static fn(string $entry): bool => $entry !== '')));
    }

    /** The browser's address; the nearest proxy's when the chain cannot be read past it. */
    public function clientIp(ServerRequestInterface $request): string
    {
        $address = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if (!$this->trusts($address)) {
            return $address;
        }

        $chain = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));
        foreach (array_reverse($chain) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $address = $hop;
            if (!$this->trusts($hop)) {
                break;
            }
        }

        return $address;
    }

    /** The browser's address as a throttle counts it (network()). */
    public function clientNetwork(ServerRequestInterface $request, int $bits = self::IPV6_NETWORK): string
    {
        return self::network($this->clientIp($request), $bits);
    }

    /**
     * An address as a throttle counts it: IPv4 as it is, IPv6 by its first `$bits` bits. A host gets a whole /64 (often
     * a /48) and could use a fresh address for every try — never waiting, and leaving a throttle file per try.
     */
    public static function network(string $address, int $bits = self::IPV6_NETWORK): string
    {
        $packed = inet_pton($address);
        if ($packed === false || strlen($packed) !== 16 || str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return $address;
        }

        return inet_ntop(str_pad(self::prefix($packed, $bits), 16, "\0")) . '/' . $bits;
    }

    /** The browser reached the shop over HTTPS — directly, or through a proxy that says so. */
    public function isHttps(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();

        return $request->getUri()->getScheme() === 'https'
            || strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https'
            || (string) ($server['SERVER_PORT'] ?? '') === '443'
            || strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0])) === 'https'
            || str_contains(strtolower($request->getHeaderLine('CF-Visitor')), '"https"');
    }

    private function trusts(string $address): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }

        foreach ($this->trustedProxies as $proxy) {
            if (self::within($packed, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the packed address is the proxy's, or inside its range ("10.0.0.0/8", "2400:cb00::/32"). A range whose
     * length is no number ("10.0.0.1/", a typo in config.php) is trusted for nothing — read as /0 it would trust everyone.
     */
    private static function within(string $packed, string $proxy): bool
    {
        [$network, $bits] = str_contains($proxy, '/') ? explode('/', $proxy, 2) : [$proxy, null];
        $range = inet_pton($network);
        if ($range === false || strlen($range) !== strlen($packed) || ($bits !== null && !ctype_digit($bits))) {
            return false;
        }

        $length = strlen($packed) * 8;
        $bits = $bits === null ? $length : (int) $bits;

        return $bits <= $length && self::prefix($packed, $bits) === self::prefix($range, $bits);
    }

    /** The first `$bits` bits of a packed address: its whole bytes, then the byte they end in with the rest zeroed. */
    private static function prefix(string $packed, int $bits): string
    {
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        return substr($packed, 0, $whole) . ($rest === 0 ? '' : chr(ord($packed[$whole]) & (0xFF << (8 - $rest)) & 0xFF));
    }
}
