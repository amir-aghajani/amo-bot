<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Store\Models\Website;

/**
 * `GET /me`'s `staff`: what the admin API (Http\StaffMiddleware) will let this customer do, so the site offers only that.
 * Null for a customer who is no admin, and while the website lets no admin in.
 */
final class StaffPresenter
{
    /**
     * The grants; whether a strong sign-in is asked and whether this session is one; until when the session may use the
     * admin API at all (StaffMiddleware::SIGN_IN_HOURS) and until when it counts as recent (granted operations and payment
     * approvals ask that). A null time means: prove a way in again first.
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
