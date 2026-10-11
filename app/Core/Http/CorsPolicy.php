<?php

declare(strict_types=1);

namespace App\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Which browser origins may read the answers to which requests (CorsMiddleware). Bound to the websites' policy: the
 * panels are served from the shop's own origin and answer no other.
 */
interface CorsPolicy
{
    /**
     * Whether a page on `$origin` may call this address and read the answer. Asked for every request with an Origin; a
     * policy that cannot tell answers false, never throws.
     */
    public function allows(ServerRequestInterface $request, string $origin): bool;
}
