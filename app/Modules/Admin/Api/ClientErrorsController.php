<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Core\Session\Session;
use App\Modules\Admin\Services\ClientErrors;
use App\Modules\Auth\Principal;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /client-errors (both panels): a failure in the panel's own code, reported by its page and logged (ClientErrors). */
final class ClientErrorsController extends ApiController
{
    public function __construct(
        private readonly ClientErrors $errors,
        private readonly Session $session,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // A report needs nothing from the session: release it so the page's other requests do not wait.
        $this->session->release();
        $this->errors->report($request, Principal::of($request));

        return $this->noContent($response);
    }
}
