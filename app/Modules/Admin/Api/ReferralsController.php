<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Referrals\Services\ReferralAlerts;
use App\Modules\Referrals\Services\ReferralDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «زیرمجموعه‌گیری»: the referral program's numbers and lists — referrers, invitees, commissions, and alerts on referrals
 * that look made up, which support clears.
 */
final class ReferralsController extends ApiController
{
    public function __construct(
        private readonly ReferralDirectory $directory,
        private readonly ReferralAlerts $alerts,
    ) {}

    /** GET /referrals */
    public function summary(Request $request, Response $response): Response
    {
        return $this->json($response, ['summary' => $this->directory->summary()]);
    }

    /** GET /referrals/referrers?search=&page= */
    public function referrers(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->referrers($this->pageRequest($request))->toArray('referrers'));
    }

    /** GET /referrals/invitees?search=&referrer=&sort=&dir=&page= */
    public function invitees(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->invitees($this->pageRequest($request))->toArray('invitees'));
    }

    /** GET /referrals/commissions?search=&page= */
    public function commissions(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->commissions($this->pageRequest($request))->toArray('commissions'));
    }

    /** GET /referrals/alerts?search=&page= */
    public function alerts(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->alerts($this->pageRequest($request))->toArray('alerts'));
    }

    /**
     * POST /referrals/alerts/{id}/clear — the referrer earns gifts again from now on; what was withheld stays so
     *
     * @param array<string, string> $args
     */
    public function clearAlert(Request $request, Response $response, array $args): Response
    {
        $this->alerts->clear($this->load(ReferralAlert::class, $args), Principal::of($request)->actor());

        return $this->noContent($response);
    }
}
