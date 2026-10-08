<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Store\Models\Website;

/**
 * A signed-in customer as one of the shop's admins on its website (`GET /me`'s `staff`), so the site knows what of the
 * admin API (`/admin/*`, Http\StaffMiddleware) to offer them: null for a customer who is no admin of the shop, and while
 * the website lets no admin in.
 */
final class StaffPresenter
{
    /**
     * What the website grants its admins beyond the shop's daily work; whether it asks them a strong sign-in, and whether
     * this session is one (CustomerSessions::isStrong() — POST /me/reauthenticate makes one of a password alone); until
     * when its sign-in lets it work the admin API at all (StaffMiddleware::SIGN_IN_HOURS), and until when it counts as
     * recent, which a granted operation — and approving a payment — asks too (either null: prove a way in again first).
     *
     * @return array{grants: list<string>, strong_sign_in: bool, signed_in_strongly: bool, signed_in_until: string|null, recent_until: string|null}|null
     */
    public static function present(Website $website, Customer $customer): ?array
    {
        if (!$customer->user->isAdmin() || !$website->staff_enabled) {
            return null;
        }
        $session = $customer->session;

        return [
            'grants' => $website->staffGrants(),
            'strong_sign_in' => $website->staff_strong_sign_in,
            'signed_in_strongly' => CustomerSessions::isStrong($session),
            'signed_in_until' => StaffMiddleware::signedInUntil($session)?->toIso8601String(),
            'recent_until' => CustomerSessions::provenUntil($session, CustomerSessions::RECENT_SECONDS)?->toIso8601String(),
        ];
    }
}
