<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Rewards\Services\LotteryDraws;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The nightly draws («قرعه‌کشی شبانه», both panels): each draw with its prize and winners, newest first. */
final class LotteryController extends ApiController
{
    public function __construct(private readonly LotteryDraws $draws) {}

    /** GET /lottery/draws?page= */
    public function draws(Request $request, Response $response): Response
    {
        return $this->json($response, $this->draws->page($this->pageRequest($request))->toArray('draws'));
    }
}
