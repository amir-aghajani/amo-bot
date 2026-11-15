<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

/**
 * The path a panel serves its subscription links under, read off one client's link — for the panels whose subscription
 * route says whose a link's token is (`{path}/{token}/info`: Marzban, PasarGuard, Rebecca), which the shop asks to find
 * the client a link is of. The path is the folders the link's token is in; it may be several folders (the panel's
 * setting), and a prefix in front of the links (the panel's own URL prefix, an admin's subdomain, a proxy's path) is not
 * the panel's: with more than one folder, the last FOLDERS_MAX are tried, longest first, by asking the panel about the
 * link's own token — the path that answers with its client is the one.
 */
final class SubscriptionPath
{
    /** The most folders before a link's token tried as the panel's subscription path. */
    private const FOLDERS_MAX = 3;

    /**
     * The path `$link` is served under — `$answers` says whether the panel's route under a path answers a token with the
     * link's own client —; null for a link without a folder before its token, or with none answering.
     *
     * @param \Closure(string $path, string $token): bool $answers
     */
    public static function find(string $link, \Closure $answers): ?string
    {
        $folders = array_values(array_filter(explode('/', (string) parse_url($link, PHP_URL_PATH)), static fn(string $part): bool => $part !== ''));
        $token = array_pop($folders);
        if ($token === null || $folders === []) {
            return null;
        }

        $paths = [];
        for ($count = min(self::FOLDERS_MAX, count($folders)); $count > 0; $count--) {
            $paths[] = '/' . implode('/', array_slice($folders, -$count));
        }
        if (count($paths) === 1) {
            return $paths[0];
        }

        foreach ($paths as $path) {
            if ($answers($path, $token)) {
                return $path;
            }
        }

        return null;
    }
}
