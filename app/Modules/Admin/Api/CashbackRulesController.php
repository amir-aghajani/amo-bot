<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Rewards\Models\CashbackRule;
use App\Modules\Rewards\Services\CashbackRules;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The shop's cashback rules («کش‌بک», both panels): a percent of what an order paid, for the order kinds, customer group
 * and way to pay it names, with its switch. Whether cashback runs at all is the bot settings' `cashback` group.
 */
final class CashbackRulesController extends ApiController
{
    public function __construct(private readonly CashbackRules $rules) {}

    /** GET /cashback/rules */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['rules' => $this->rules->all()]);
    }

    /**
     * POST /cashback/rules — {percent, order_types, customer_group_id?, payment_method_id?, enabled?}
     *
     * @throws ValidationException
     */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['rule' => CashbackRules::present($this->rules->create($this->input($request)))], 201);
    }

    /**
     * PUT /cashback/rules/{id} — {percent, order_types, customer_group_id?, payment_method_id?, enabled?}
     *
     * @param array<string, string> $args
     * @throws ValidationException
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['rule' => CashbackRules::present($this->rules->update($this->load(CashbackRule::class, $args), $this->input($request)))]);
    }

    /**
     * PATCH /cashback/rules/{id} — {enabled}: the rule's switch in the list.
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `enabled`: no switch
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['rule' => CashbackRules::present($this->rules->setEnabled($this->load(CashbackRule::class, $args), $this->switch($request, 'enabled')))]);
    }

    /**
     * DELETE /cashback/rules/{id}
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->rules->delete($this->load(CashbackRule::class, $args));

        return $this->noContent($response);
    }
}
