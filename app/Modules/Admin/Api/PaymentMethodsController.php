<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The ways customers can pay: rows added from the driver picker, each with its own settings, switched and ordered — the
 * built-in wallet, and a row payments were made with, are not deleted (409).
 */
final class PaymentMethodsController extends ApiController
{
    public function __construct(private readonly PaymentMethods $methods) {}

    /** GET /payment-methods */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['methods' => $this->methods->all()]);
    }

    /** GET /payment-methods/drivers — the gateway drivers a method can be made from. */
    public function drivers(Request $request, Response $response): Response
    {
        return $this->json($response, ['drivers' => $this->methods->drivers()]);
    }

    /** POST /payment-methods — {driver, label, enabled?, ...the driver's fields} */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['method' => $this->methods->presentOne($this->methods->create($this->input($request)))], 201);
    }

    /**
     * PUT /payment-methods/{id} — the whole form again.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $method = $this->methods->update($this->load(PaymentMethod::class, $args), $this->input($request));

        return $this->json($response, ['method' => $this->methods->presentOne($method)]);
    }

    /**
     * PATCH /payment-methods/{id} — {enabled}
     *
     * @param array<string, string> $args
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $method = $this->methods->setEnabled($this->load(PaymentMethod::class, $args), $this->switch($request, 'enabled'));

        return $this->json($response, ['method' => $this->methods->presentOne($method)]);
    }

    /** POST /payment-methods/reorder — {ids: [..]} in the new checkout order. */
    public function reorder(Request $request, Response $response): Response
    {
        $this->methods->reorder($this->reorderIds($request));

        return $this->json($response, ['methods' => $this->methods->all()]);
    }

    /**
     * DELETE /payment-methods/{id}
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->methods->delete($this->load(PaymentMethod::class, $args));

        return $this->noContent($response);
    }
}
