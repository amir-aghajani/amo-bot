<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\Websites;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The shop's website settings (both panels, Store\Services\Websites); a secret is never sent back. */
final class WebsiteController extends ApiController
{
    public function __construct(private readonly Websites $websites) {}

    /** GET /website — made, switched off, the first time it is asked for. */
    public function show(Request $request, Response $response): Response
    {
        return $this->present($response, $this->websites->current());
    }

    /**
     * PATCH /website — any of the website's fields: a field sent is changed, one left out is kept; nothing sent changes
     * nothing.
     *
     * @throws ValidationException 422, every refusal under its field
     */
    public function update(Request $request, Response $response): Response
    {
        return $this->present($response, $this->websites->update($this->websites->current(), $this->input($request)));
    }

    /** POST /website/key — a new store key: the old base address stops working at once. */
    public function rotateKey(Request $request, Response $response): Response
    {
        return $this->present($response, $this->websites->rotateKey($this->websites->current()));
    }

    private function present(Response $response, Website $website): Response
    {
        return $this->json($response, ['website' => $this->websites->present($website)]);
    }
}
