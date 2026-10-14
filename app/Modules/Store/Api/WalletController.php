<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Store\Presenters\RecipientPresenter;
use App\Modules\Store\Services\CustomerWallet;
use App\Modules\Store\Services\CustomerWalletTransfers;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletTransfers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's wallet and its ledger (Store\Services\CustomerWallet), and «انتقال موجودی» — money sent to
 * another customer of the shop (WalletTransfers, as the bot does it; CustomerWalletTransfers).
 */
final class WalletController extends ApiController
{
    public function __construct(
        private readonly CustomerWallet $wallet,
        private readonly CustomerWalletTransfers $offer,
        private readonly WalletTransfers $transfers,
    ) {}

    /** GET /wallet — the balance, an agent's credit, what they can pay, what a top-up may be, what they may send. */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->present(Customer::of($request)->user));
    }

    /** GET /wallet/transactions — ?page=, newest line first. */
    public function transactions(Request $request, Response $response): Response
    {
        $page = $this->wallet->ledger(Customer::of($request)->user, $this->pageRequest($request));

        return $this->json($response, $page->toArray('transactions'));
    }

    /**
     * GET /wallet/transfer?telegram_id= — «انتقال موجودی», before it is done: who the shop's customer with that Telegram
     * id is, so the website asks the customer to confirm them — only while they may send someone the least
     * (WalletTransfers::recipient()).
     *
     * @throws ValidationException 422: the shop offers none, on `amount` while the customer may send nothing, or on
     *                             `telegram_id`
     * @throws TooManyAttemptsException 429
     */
    public function transferRecipient(Request $request, Response $response): Response
    {
        $recipient = $this->transfers->recipient(Customer::of($request)->user, $request->getQueryParams()['telegram_id'] ?? null);

        return $this->json($response, ['recipient' => RecipientPresenter::present($recipient)]);
    }

    /**
     * POST /wallet/transfer — {telegram_id, amount}: «انتقال موجودی», the money sent to the shop's customer with that
     * Telegram id (WalletTransfers::transferById()); the same Idempotency-Key again is answered by the transfer it made.
     * The answer is the transfer and the wallet as it is now.
     *
     * @throws ValidationException 422: the shop offers none, or on `telegram_id`, `amount` or `idempotency_key`
     * @throws TooManyAttemptsException 429
     */
    public function transfer(Request $request, Response $response): Response
    {
        $key = IdempotencyKey::of($request);
        $customer = Customer::of($request)->user;
        $input = $this->input($request);
        $transfer = $this->transfers->transferById($customer, $input['telegram_id'] ?? null, $input['amount'] ?? null, $key);

        return $this->json($response, ['transfer' => CustomerWalletTransfers::present($transfer), 'wallet' => $this->present($customer)]);
    }

    /** @return array<string, mixed> The wallet (CustomerWallet::present()) and what the customer may send now (`transfer`). */
    private function present(User $customer): array
    {
        return $this->wallet->present($customer) + ['transfer' => $this->offer->offer($customer)];
    }
}
