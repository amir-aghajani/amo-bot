<?php

declare(strict_types=1);

namespace App\Modules\Store\Contracts;

use App\Modules\Accounts\Contracts\SignInSite;

/**
 * What the Store API is opened through (Http\StoreMiddleware): a shop's website (Models\Website), or its Telegram mini
 * app (Models\MiniApp), each under a store key of its own. Both are sign-in sites — the mini app's ways in are all off:
 * its customers sign in by Telegram's launch data alone — and each says whether it shows the shop's reviews.
 */
interface Door extends SignInSite
{
    /** The store key the Store API answers this door under. */
    public function storeKey(): string;

    /** Whether it shows the reviews support approved and takes new ones (GET and POST /reviews). */
    public function showsReviews(): bool;
}
