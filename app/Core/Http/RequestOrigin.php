<?php

declare(strict_types=1);

namespace App\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a request came from: the browser's address, and whether it reached the shop over HTTPS.
 *
 * Behind a reverse proxy (nginx in front of PHP-FPM, Cloudflare) REMOTE_ADDR is the proxy's, and the browser's address
 * is in the X-Forwarded-For chain each proxy appends to. Anyone can write that header, so it is read only through the
 * proxies the shop trusts (TRUSTED_PROXIES, addresses or CIDR ranges): from the connection backwards, every hop that is
 * a trusted proxy is passed over, and the first that is not is the browser. An address taken from a stranger's header
 * would let them pick a new one for every sign-in attempt. Cloudflare appends the visitor to the chain too, so listing
 * its ranges is all a shop behind it needs (or the web server restores the address itself, mod_remoteip / real_ip).
 *
 * HTTPS is read from the forwarding headers of anyone: it only ever adds protection — HSTS, a secure session cookie —
 * which a browser on plain HTTP ignores, so a forged header gains nothing.
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
     * Whether the request came straight from this machine: its connection is the loopback's, and nothing forwarded it —
     * a reverse proxy or a tunnel on the machine (nginx in front of Apache, cloudflared, ngrok) makes every visitor's
     * connection look local, and says so in a forwarding header. Whatever trusts no one but the machine's own owner (the
     * traces APP_DEBUG shows) goes to such a request alone.
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

    /**
     * The browser's address as a throttle counts its tries (network()) — an IPv6 one by its first `$bits` bits, its /64
     * unless a count reaches wider.
     */
    public function clientNetwork(ServerRequestInterface $request, int $bits = self::IPV6_NETWORK): string
    {
        return self::network($this->clientIp($request), $bits);
    }

    /**
     * An address as a throttle counts its tries: an IPv4 one — mapped into IPv6 or not — as it is, an IPv6 one by its
     * first `$bits` bits (IPV6_NETWORK: its /64). A host is handed the whole of a /64 — and often a /48 — and may try from
     * another address of it every time: counted one by one, it would never wait, and would leave a file in the throttle's
     * folder for every try. What is no address is counted as it is.
     */
    public static function network(string $address, int $bits = self::IPV6_NETWORK): string
    {
        $packed = inet_pton($address);
        if ($packed === false || strlen($packed) !== 16 || str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return $address;
        }

        $whole = intdiv($bits, 8);
        $network = substr($packed, 0, $whole);
        if ($bits % 8 !== 0) {
            $network .= chr(ord($packed[$whole]) & ((0xFF << (8 - $bits % 8)) & 0xFF));
        }

        return inet_ntop(str_pad($network, 16, "\0")) . '/' . $bits;
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

    /** Whether the packed address is the proxy's, or inside its range ("10.0.0.0/8", "2400:cb00::/32"). */
    private static function within(string $packed, string $proxy): bool
    {
        [$network, $bits] = str_contains($proxy, '/') ? explode('/', $proxy, 2) : [$proxy, null];
        $range = inet_pton($network);
        if ($range === false || strlen($range) !== strlen($packed)) {
            return false;
        }

        $length = strlen($packed) * 8;
        $bits = $bits === null ? $length : (int) $bits;
        if ($bits < 0 || $bits > $length) {
            return false;
        }

        $whole = intdiv($bits, 8);
        if (strncmp($packed, $range, $whole) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($range[$whole]) & $mask);
    }
}
