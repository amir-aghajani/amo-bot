<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Rewards\Services\LotteryBoard;
use App\Modules\Store\Exceptions\ProgramOffException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A signed-in customer's view of the nightly draw («قرعه‌کشی شبانه», Rewards\Services\LotteryBoard). */
final class LotteryController extends ApiController
{
    public function __construct(private readonly LotteryBoard $board) {}

    /**
     * GET /lottery
     *
     * @throws ProgramOffException 404 while the shop runs none
     */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->board->for(Customer::of($request)->user) ?? throw ProgramOffException::lottery());
    }
}
