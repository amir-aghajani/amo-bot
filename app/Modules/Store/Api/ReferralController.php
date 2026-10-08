<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Referrals\Services\ReferralSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's part in the referral program on the shop's website, as the bot's «👥 زیرمجموعه‌گیری» has it:
 * the program's terms, their invite code — made the first time it is asked, as the bot makes it, whether or not the
 * program runs — and their bot's link, and what their referrals brought them.
 */
final class ReferralController extends ApiController
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly ReferralSettings $settings,
    ) {}

    /** GET /referral */
    public function show(Request $request, Response $response): Response
    {
        $user = Customer::of($request)->user;
        ['referrals' => $invited, 'earned' => $earned] = $this->referrals->statsFor($user);

        return $this->json($response, [
            'enabled' => $this->settings->enabled(),
            'rate' => $this->settings->rate(),
            'first_only' => $this->settings->firstOnly(),
            'code' => $this->referrals->codeFor($user),
            'bot_link' => $this->referrals->linkFor($user),
            'invited' => $invited,
            'earned' => $earned,
        ]);
    }
}
