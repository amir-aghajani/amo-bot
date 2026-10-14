<?php

declare(strict_types=1);

namespace App\Modules\Providers\Enums;

/**
 * Why a panel could not be reached, grouped by what the admin has to fix: its name, the port or firewall, the network
 * (or a timeout too short), its TLS certificate, or the SOCKS5 proxy it is reached through.
 */
enum ConnectionFailure: string
{
    case Dns = 'dns';
    case Refused = 'refused';
    case Timeout = 'timeout';
    case Tls = 'tls';
    case Proxy = 'proxy';
    case Other = 'other';

    /** cURL's error codes (CURLE_*) by what they mean for the admin. */
    private const CURL = [
        5 => self::Proxy,     // COULDNT_RESOLVE_PROXY
        6 => self::Dns,       // COULDNT_RESOLVE_HOST
        7 => self::Refused,   // COULDNT_CONNECT
        28 => self::Timeout,  // OPERATION_TIMEDOUT
        35 => self::Tls,      // SSL_CONNECT_ERROR
        51 => self::Tls,      // PEER_FAILED_VERIFICATION
        53 => self::Tls,      // SSL_ENGINE_NOTFOUND
        58 => self::Tls,      // SSL_CERTPROBLEM
        59 => self::Tls,      // SSL_CIPHER
        60 => self::Tls,      // SSL_CACERT
        77 => self::Tls,      // SSL_CACERT_BADFILE
        83 => self::Tls,      // SSL_ISSUER_ERROR
        90 => self::Tls,      // SSL_PINNEDPUBKEYNOTMATCH
        91 => self::Tls,      // SSL_INVALIDCERTSTATUS
        97 => self::Proxy,    // PROXY: the SOCKS handshake failed (cURL 7.73 and later)
    ];

    /**
     * What a transport's error message says went wrong: cURL's code when it names one, its wording otherwise. A request
     * sent through a proxy (`$proxied`) connects to nothing but the proxy and looks up no name but the proxy's, so a name
     * not found (cURL says 6, not 5, of a SOCKS proxy's) or a connection that could not be made — the proxy down or out of
     * reach, or a SOCKS handshake an older cURL reports so — is the proxy's; a timeout once connected is the panel's.
     */
    public static function of(string $message, bool $proxied = false): self
    {
        if (preg_match('/cURL error (\d+)/', $message, $match) === 1) {
            $code = (int) $match[1];
            $connecting = in_array($code, [6, 7], true) || ($code === 28 && preg_match('/cURL error 28: (?:Connection timed out|Resolving timed out|Failed to connect)/', $message) === 1);

            return $proxied && $connecting ? self::Proxy : (self::CURL[$code] ?? self::Other);
        }

        $lower = strtolower($message);

        return match (true) {
            preg_match('/\bSOCKS/', $message) === 1, str_contains($lower, 'resolve proxy') => self::Proxy,
            str_contains($lower, 'resolve host'), str_contains($lower, 'name or service not known'), str_contains($lower, 'getaddrinfo') => self::Dns,
            str_contains($lower, 'timed out'), str_contains($lower, 'timeout') => self::Timeout,
            str_contains($lower, 'ssl'), str_contains($lower, 'tls'), str_contains($lower, 'certificate') => self::Tls,
            str_contains($lower, 'refused'), str_contains($lower, 'failed to connect'), str_contains($lower, 'no route to host'), str_contains($lower, 'unreachable') => self::Refused,
            default => self::Other,
        };
    }

    /**
     * Whether the request cannot have reached the panel: no address for its name, no connection, no TLS handshake, no
     * way through its proxy. A write that failed this way did not happen; one that timed out or was cut off may have.
     */
    public function beforeRequest(): bool
    {
        return in_array($this, [self::Dns, self::Refused, self::Tls, self::Proxy], true);
    }
}
