<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Discounts\Services\Sales;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The shop's timed sales («فروش ویژه», Discounts\Services\Sales): listed, made, edited whole, switched, deleted. */
final class SalesController extends ApiController
{
    public function __construct(private readonly Sales $sales) {}

    /** GET /sales — every sale, the newest first. */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['sales' => $this->sales->list()]);
    }

    /**
     * POST /sales — the sale's form.
     *
     * @throws ValidationException 422, every refusal at once
     */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['sale' => $this->sales->present($this->sales->create($this->input($request)))], 201);
    }

    /**
     * PUT /sales/{id} — the sale's form, whole.
     *
     * @param array<string, string> $args
     * @throws ValidationException 422, every refusal at once
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $sale = $this->sales->update($this->load(Sale::class, $args), $this->input($request));

        return $this->json($response, ['sale' => $this->sales->present($sale)]);
    }

    /**
     * PATCH /sales/{id} — {enabled}
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `enabled`
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $sale = $this->sales->setEnabled($this->load(Sale::class, $args), $this->switch($request, 'enabled'));

        return $this->json($response, ['sale' => $this->sales->present($sale)]);
    }

    /**
     * DELETE /sales/{id}
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->sales->delete($this->load(Sale::class, $args));

        return $this->noContent($response);
    }
}
