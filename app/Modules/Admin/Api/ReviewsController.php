<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\ReviewDirectory;
use App\Modules\Reviews\Services\Reviews;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The reviews screen (both panels, and the shop's website for its admins): the list, and what support decides about a
 * review — approve it (the website shows it), reject it (it does not), delete it —, each Reviews\Services\Reviews', the
 * request's principal the one who did it; the answer is the review as it stands after it.
 */
final class ReviewsController extends ApiController
{
    public function __construct(
        private readonly ReviewDirectory $directory,
        private readonly Reviews $reviews,
    ) {}

    /** GET /reviews?status=&search=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->search(PageRequest::fromQuery($request->getQueryParams()))->toArray('reviews'));
    }

    /**
     * POST /reviews/{id}/approve
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `status`
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->review($response, $this->reviews->approve($this->find($args), Principal::of($request)->actor()));
    }

    /**
     * POST /reviews/{id}/reject
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `status`
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->review($response, $this->reviews->reject($this->find($args), Principal::of($request)->actor()));
    }

    /**
     * DELETE /reviews/{id}
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->reviews->delete($this->find($args), Principal::of($request)->actor());

        return $this->noContent($response);
    }

    /**
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    private function find(array $args): Review
    {
        return $this->load(Review::class, $args, ['user']);
    }

    private function review(Response $response, Review $review): Response
    {
        return $this->json($response, ['review' => $this->directory->present($review)]);
    }
}
