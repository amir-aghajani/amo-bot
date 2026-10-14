<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * The links a panel gave out for one of its clients, as a customer sends them back to claim the service
 * (ServiceClaims): a subscription link — the parts of its path, where a panel puts the client's subscription id or
 * signed token — or one of its configs — the credentials in it: a vless or vmess id, a trojan, Shadowsocks or hysteria
 * password, a tuic id and password. A message's links are read together, so a config after an unrelated web address is
 * not missed. Each connector says whose client they are (ProviderInterface::findClientByLink()). Only values long
 * enough to be minted by a panel are kept, so a short guess finds no one — though a value a person chose by hand may
 * still be guessed.
 */
final class ClientLink
{
    /** The shortest value kept: a panel mints far longer ids, tokens and passwords. */
    public const MIN_LENGTH = 8;

    /**
     * A link in a message: a config's scheme up to the next space (its name after `#` may be in any script), or a web
     * address in the characters an address is written in — a word written on after it is not part of it.
     */
    public const PATTERN = '~\b(?:vless|vmess|trojan|ss|hysteria2|hy2|tuic)://[^\s<>"\']+|\bhttps?://[A-Za-z0-9\-._\~:/?#\[\]@!$&()*+,;=%]+~i';

    /** The longest value kept; anything longer is no panel's. */
    private const MAX_LENGTH = 512;

    /** The most links of one message read: a customer pastes one service, or a few of its configs. */
    private const LINKS_MAX = 5;

    /** Punctuation a sentence puts after a link — Persian too —, never part of it. */
    private const TRAILING = '/[.,;:!?)\]»،؛؟]+$/u';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param list<string> $tokens A subscription link's path parts, the last first
     * @param list<string> $credentials A config's credentials, ids in lower case
     */
    private function __construct(
        public readonly array $tokens,
        public readonly array $credentials,
    ) {}

    /** What the links in `$text` carry to look for, the first LINKS_MAX of them together; null when none carries any. */
    public static function find(string $text): ?self
    {
        $tokens = [];
        $credentials = [];
        foreach (array_slice(self::links($text), 0, self::LINKS_MAX) as $link) {
            [$linkTokens, $linkCredentials] = self::parse($link);
            array_push($tokens, ...$linkTokens);
            array_push($credentials, ...$linkCredentials);
        }

        return self::of($tokens, $credentials);
    }

    /**
     * The links in `$text` (PATTERN) as find() reads them, without the punctuation a sentence puts after them.
     *
     * @return list<string>
     */
    public static function links(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_map(static fn(string $link): string => preg_replace(self::TRAILING, '', $link) ?? $link, $matches[0]);
    }

    /** Whether a subscription link among them carries `$id`: a client's subscription id, or its token. */
    public function hasToken(string $id): bool
    {
        return $id !== '' && in_array(self::normal($id), $this->tokens, true);
    }

    /** Whether a config among them carries one of the client's `$credentials` (its ids and passwords; empty ones skipped). */
    public function hasCredential(string ...$credentials): bool
    {
        foreach ($credentials as $credential) {
            if ($credential !== '' && in_array(self::normal($credential), $this->credentials, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One link's tokens (a subscription link's last two path parts, the last first) and credentials (a config's).
     *
     * @return array{list<string>, list<string>}
     */
    private static function parse(string $link): array
    {
        $scheme = strtolower((string) strstr($link, '://', true));
        if ($scheme === 'http' || $scheme === 'https') {
            $parts = array_filter(explode('/', (string) parse_url($link, PHP_URL_PATH)), static fn(string $part): bool => $part !== '');

            // A panel puts the client's own part last, or before a format (`/sub/{token}/clash`).
            return [array_slice(array_reverse(array_map(rawurldecode(...), array_values($parts))), 0, 2), []];
        }

        // What follows `?` is the config's options, and `#` its name.
        $rest = (string) preg_replace('/[?#].*$/s', '', substr($link, strlen($scheme) + 3));

        return [[], match ($scheme) {
            'vmess' => self::vmess($rest),
            'ss' => self::shadowsocks($rest),
            default => self::userinfo($rest),
        }];
    }

    /**
     * vless, trojan, hysteria2, tuic: the part before the host — an id, a password, or tuic's "id:password" (both kept).
     *
     * @return list<string>
     */
    private static function userinfo(string $rest): array
    {
        $at = strrpos($rest, '@');

        return $at === false ? [] : self::pieces(rawurldecode(substr($rest, 0, $at)));
    }

    /**
     * vmess: base64 of a JSON whose `id` is the client's, or — as some apps write it — an id before the host.
     *
     * @return list<string>
     */
    private static function vmess(string $rest): array
    {
        if (str_contains($rest, '@')) {
            return self::userinfo($rest);
        }
        $config = json_decode((string) self::base64($rest), true);

        return is_array($config) && is_string($config['id'] ?? null) ? [$config['id']] : [];
    }

    /**
     * Shadowsocks: "method:password" — base64 before the host, plain (a 2022 key's form), or with the host inside the
     * base64 (the old form). A 2022 password "server key:user key" is kept whole and as its user half — the server half
     * is every config's on that inbound.
     *
     * @return list<string>
     */
    private static function shadowsocks(string $rest): array
    {
        $at = strrpos($rest, '@');
        if ($at === false) {
            $decoded = (string) self::base64($rest);
            $at = strrpos($decoded, '@');
            $info = $at === false ? '' : substr($decoded, 0, $at);
        } else {
            $raw = rawurldecode(substr($rest, 0, $at));
            $decoded = self::base64($raw);
            $info = $decoded !== null && str_contains($decoded, ':') ? $decoded : $raw;
        }
        $colon = strpos($info, ':');
        if ($colon === false) {
            return [];
        }
        $password = substr($info, $colon + 1);
        $key = strrpos($password, ':');

        return $key === false ? [$password] : [$password, substr($password, $key + 1)];
    }

    /**
     * A value whole and, when it has colons, each piece of it.
     *
     * @return list<string>
     */
    private static function pieces(string $value): array
    {
        return str_contains($value, ':') ? [$value, ...explode(':', $value)] : [$value];
    }

    /** Base64 in either alphabet, padded or not; null when it is not. */
    private static function base64(string $text): ?string
    {
        $text = strtr(trim($text), '-_', '+/');
        $decoded = base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /** An id in lower case, as panels keep them; anything else as it is. */
    private static function normal(string $value): string
    {
        return preg_match(self::UUID, $value) === 1 ? strtolower($value) : $value;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $credentials
     */
    private static function of(array $tokens, array $credentials): ?self
    {
        $kept = static fn(array $values): array => array_values(array_unique(array_filter(
            array_map(self::normal(...), $values),
            static fn(string $value): bool => strlen($value) >= self::MIN_LENGTH && strlen($value) <= self::MAX_LENGTH,
        )));
        $tokens = $kept($tokens);
        $credentials = $kept($credentials);

        return $tokens === [] && $credentials === [] ? null : new self($tokens, $credentials);
    }
}
