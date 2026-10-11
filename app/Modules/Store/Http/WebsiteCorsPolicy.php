<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Http\CorsPolicy;
use App\Core\Http\Urls;
use App\Core\Installation;
use App\Modules\Store\Services\Websites;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Which pages may call the Store API from a browser: under a website's key (Urls::STORE), its own origin and the ones its
 * owner listed, while the website is open. Any other path — the panels' API above all — allows no origin; a shop not
 * installed yet, or a database that does not answer, allows none.
 */
final class WebsiteCorsPolicy implements CorsPolicy
{
    public function __construct(
        private readonly Websites $websites,
        private readonly Installation $installation,
        private readonly LoggerInterface $logger,
        /** The prefix requests arrive under (the container's `http.base_path`). */
        private readonly string $basePath,
    ) {}

    public function allows(ServerRequestInterface $request, string $origin): bool
    {
        $key = $this->keyOf($request);
        if ($key === null || !$this->installation->isInstalled()) {
            return false;
        }

        try {
            return $this->websites->open($key)?->allowsOrigin($origin) ?? false;
        } catch (\Throwable $e) {
            $this->logger->warning('A cross-origin request to the Store API could not be checked against its website: {message}', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /** The store key in a Store API address, read from the decoded path as the router reads it; null for any other address. */
    private function keyOf(ServerRequestInterface $request): ?string
    {
        $prefix = $this->basePath . strstr(Urls::STORE, '{', true);
        $path = rawurldecode($request->getUri()->getPath());
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        return preg_match('~^([0-9a-f]{24})(?:/|$)~', substr($path, strlen($prefix)), $key) === 1 ? $key[1] : null;
    }
}
