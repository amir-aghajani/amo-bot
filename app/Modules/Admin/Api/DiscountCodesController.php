<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Discounts\Exceptions\DiscountInUseException;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Discounts\Services\DiscountCodes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The shop's discount codes («کدهای تخفیف»): listed with their uses, made, edited, switched, deleted while unused. */
final class DiscountCodesController extends ApiController
{
    public function __construct(private readonly DiscountCodes $codes) {}

    /** GET /discount-codes — ?search=&page=: the newest first. */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->codes->page($this->pageRequest($request))->toArray('codes'));
    }

    /**
     * POST /discount-codes — the code's form.
     *
     * @throws ValidationException 422, every refusal at once
     */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['code' => $this->codes->present($this->codes->create($this->input($request)))], 201);
    }

    /**
     * PUT /discount-codes/{id} — the code's form, whole.
     *
     * @param array<string, string> $args
     * @throws ValidationException 422, every refusal at once
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $code = $this->codes->update($this->load(DiscountCode::class, $args), $this->input($request));

        return $this->json($response, ['code' => $this->codes->present($code)]);
    }

    /**
     * PATCH /discount-codes/{id} — {enabled}
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `enabled`
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $code = $this->codes->setEnabled($this->load(DiscountCode::class, $args), $this->switch($request, 'enabled'));

        return $this->json($response, ['code' => $this->codes->present($code)]);
    }

    /**
     * DELETE /discount-codes/{id} — while no order used it.
     *
     * @param array<string, string> $args
     * @throws DiscountInUseException 409
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->codes->delete($this->load(DiscountCode::class, $args));

        return $this->noContent($response);
    }
}
