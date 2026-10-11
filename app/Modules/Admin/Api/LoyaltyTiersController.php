<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Discounts\Services\LoyaltyTiers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The loyalty tiers («سطوح وفاداری», both panels): each with the least a customer must have spent, its discount and its
 * extra cashback, listed by that threshold with how many customers each holds.
 */
final class LoyaltyTiersController extends ApiController
{
    public function __construct(private readonly LoyaltyTiers $tiers) {}

    /** GET /loyalty/tiers */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['tiers' => $this->tiers->all()]);
    }

    /**
     * POST /loyalty/tiers — {name, min_spent, discount_percent, cashback_percent}
     *
     * @throws ValidationException
     */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['tier' => $this->tiers->presentOne($this->tiers->create($this->input($request)))], 201);
    }

    /**
     * PUT /loyalty/tiers/{id} — {name, min_spent, discount_percent, cashback_percent}
     *
     * @param array<string, string> $args
     * @throws ValidationException
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['tier' => $this->tiers->presentOne($this->tiers->update($this->load(LoyaltyTier::class, $args), $this->input($request)))]);
    }

    /**
     * DELETE /loyalty/tiers/{id} — its customers fall to the tier below.
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->tiers->delete($this->load(LoyaltyTier::class, $args));

        return $this->noContent($response);
    }
}
