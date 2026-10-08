<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Referrals\Services\ReferralDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «زیرمجموعه‌گیری»: the referral program's numbers, and its three lists — the customers whose links brought
 * someone, the customers who came through a link, and the commissions paid out. The program's rules are a group of
 * the bot settings (`PUT /bot/settings/referral`).
 */
final class ReferralsController extends ApiController
{
    public function __construct(private readonly ReferralDirectory $directory) {}

    /** GET /referrals */
    public function summary(Request $request, Response $response): Response
    {
        return $this->json($response, ['summary' => $this->directory->summary()]);
    }

    /** GET /referrals/referrers?search=&page= */
    public function referrers(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->referrers(PageRequest::fromQuery($request->getQueryParams()))->toArray('referrers'));
    }

    /** GET /referrals/invitees?search=&referrer=&sort=&dir=&page= */
    public function invitees(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->invitees(PageRequest::fromQuery($request->getQueryParams()))->toArray('invitees'));
    }

    /** GET /referrals/commissions?search=&page= */
    public function commissions(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->commissions(PageRequest::fromQuery($request->getQueryParams()))->toArray('commissions'));
    }
}
