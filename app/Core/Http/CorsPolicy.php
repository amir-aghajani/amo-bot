<?php

declare(strict_types=1);

namespace App\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Which browser origins may read the answers to which requests (CorsMiddleware): an address no policy speaks for —
 * the panels', which are served from the shop's own origin — answers no other origin.
 */
interface CorsPolicy
{
    /**
     * Whether a page on `$origin` (the request's `Origin`, as the browser sent it) may call this address and read what
     * it answers. Asked for every request that carries an Origin: an address the policy does not speak for is told
     * apart by its path alone, and a policy that cannot tell answers false — never an exception.
     */
    public function allows(ServerRequestInterface $request, string $origin): bool;
}
