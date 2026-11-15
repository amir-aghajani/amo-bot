<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Payments\Services\GatewayWebhooks;
use App\Modules\Payments\Services\PaymentMethods;
use App\Support\Input;
use App\Support\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The ways customers can pay: rows made from a driver, each with its settings, switched and ordered. The built-in wallet,
 * and a method payments were made with, are not deleted (409). The outside row, support's alone, is neither listed nor
 * found here (404). A method whose gateway takes its webhook address from the shop (Rialit) has it registered when the
 * method is saved, where the gateway has none — the save answers where the gateway sends its webhooks (`webhook`), and
 * never fails for the gateway's sake —, and its own routes to look again and to replace another address (GatewayWebhooks).
 */
final class PaymentMethodsController extends ApiController
{
    public function __construct(
        private readonly PaymentMethods $methods,
        private readonly GatewayWebhooks $webhooks,
    ) {}

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
        $method = $this->methods->create($this->input($request));

        return $this->json($response, ['method' => $this->methods->presentOne($method), 'webhook' => $this->webhooks->afterSave($method)], 201);
    }

    /**
     * PUT /payment-methods/{id} — the whole form again.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $method = $this->methods->update($this->methods->find($this->routeId($args)), $this->input($request));

        return $this->json($response, ['method' => $this->methods->presentOne($method), 'webhook' => $this->webhooks->afterSave($method)]);
    }

    /**
     * PATCH /payment-methods/{id} — {enabled}
     *
     * @param array<string, string> $args
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $method = $this->methods->setEnabled($this->methods->find($this->routeId($args)), $this->switch($request, 'enabled'));

        return $this->json($response, ['method' => $this->methods->presentOne($method)]);
    }

    /**
     * GET /payment-methods/{id}/webhook — where its gateway sends its webhooks now; a 422 on `driver` for a gateway that
     * does not take its address from the shop.
     *
     * @param array<string, string> $args
     */
    public function webhook(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['webhook' => $this->webhooks->state($this->methods->find($this->routeId($args)))]);
    }

    /**
     * POST /payment-methods/{id}/webhook — {replace?}: the shop's address registered with its gateway where it has none,
     * in place of another only with `replace`; a 422 on `driver` as GET.
     *
     * @param array<string, string> $args
     */
    public function hook(Request $request, Response $response, array $args): Response
    {
        $method = $this->methods->find($this->routeId($args));
        $replace = $this->input($request)['replace'] ?? false;
        if (!Input::isBoolean($replace)) {
            throw ValidationException::on('replace', Validation::NOT_A_SWITCH);
        }

        return $this->json($response, ['webhook' => $this->webhooks->register($method, Input::truthy($replace))]);
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
        $this->methods->delete($this->methods->find($this->routeId($args)));

        return $this->noContent($response);
    }
}
