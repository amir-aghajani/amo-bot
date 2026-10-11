<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodeDirectory;
use App\Modules\Rewards\Services\GiftCodes;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The shop's gift codes («کدهای هدیه», both panels): made in batches, listed with who redeemed each, a batch's unused codes
 * to copy, and unused codes deleted one by one or by batch.
 */
final class GiftCodesController extends ApiController
{
    public function __construct(
        private readonly GiftCodes $codes,
        private readonly GiftCodeDirectory $directory,
    ) {}

    /** GET /gift-codes — ?batch=&status=&search=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->search($this->pageRequest($request))->toArray('codes'));
    }

    /** GET /gift-codes/batches */
    public function batches(Request $request, Response $response): Response
    {
        return $this->json($response, ['batches' => $this->directory->batches()]);
    }

    /** GET /gift-codes/unused — ?batch= */
    public function unused(Request $request, Response $response): Response
    {
        return $this->json($response, ['codes' => $this->directory->unused(Input::text($request->getQueryParams(), 'batch'))]);
    }

    /** POST /gift-codes — {count, kind, amount | plan_id, batch, prefix?, expires_on?} */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, $this->codes->make($this->input($request)), 201);
    }

    /**
     * DELETE /gift-codes/{id} — one nobody took
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->codes->delete($this->load(GiftCode::class, $args));

        return $this->noContent($response);
    }

    /** POST /gift-codes/discard — {batch}: the batch's codes nobody took deleted */
    public function discard(Request $request, Response $response): Response
    {
        return $this->json($response, ['deleted' => $this->codes->deleteUnused(Input::text($this->input($request), 'batch'))]);
    }
}
