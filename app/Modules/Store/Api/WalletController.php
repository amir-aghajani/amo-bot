<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Services\CustomerWallet;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A signed-in customer's wallet on the shop's website, and its ledger (Store\Services\CustomerWallet). */
final class WalletController extends ApiController
{
    public function __construct(private readonly CustomerWallet $wallet) {}

    /** GET /wallet — the balance, an agent's credit, what they can pay, what a top-up may be. */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->wallet->present(Customer::of($request)->user));
    }

    /** GET /wallet/transactions — ?page=, newest line first. */
    public function transactions(Request $request, Response $response): Response
    {
        $page = $this->wallet->ledger(Customer::of($request)->user, PageRequest::fromQuery($request->getQueryParams()));

        return $this->json($response, $page->toArray('transactions'));
    }
}
