<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Reviews\Exceptions\ReviewsFullException;
use App\Modules\Reviews\Exceptions\SignInToReviewException;
use App\Modules\Reviews\Services\ReviewDirectory;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Store\Exceptions\ReviewsOffException;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\Website;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The customers' reviews, while the website shows them (`reviews_enabled`; off, both routes are a 404): the approved ones
 * with their summary, and a new review (Reviews\Services\Reviews) — a guest's, or a signed-in customer's — that waits for
 * support's approval.
 */
final class ReviewsController extends ApiController
{
    public function __construct(
        private readonly ReviewDirectory $directory,
        private readonly Reviews $reviews,
    ) {}

    /**
     * GET /reviews?page= — the approved ones, the newest first; how many there are and their average, `meta.summary`.
     *
     * @throws ReviewsOffException 404
     */
    public function index(Request $request, Response $response): Response
    {
        self::open($request);
        $page = $this->directory->published($this->pageRequest($request));

        return $this->json($response, ['reviews' => $page->rows, 'meta' => $page->meta() + ['summary' => $this->directory->summary()]]);
    }

    /**
     * POST /reviews — {name, rating, body, context?, avatar?, captcha?}: kept waiting on support, 201.
     *
     * @throws ReviewsOffException 404
     * @throws SignInToReviewException 403 a guest's, while the website asks no captcha
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     * @throws ReviewsFullException 503
     * @throws CaptchaUnavailableException 503
     */
    public function store(Request $request, Response $response): Response
    {
        $review = $this->reviews->submit(self::open($request), $request, Customer::optional($request)?->user, $this->input($request));

        return $this->json($response, ['review' => ['id' => $review->id, 'status' => $review->status->value]], 201);
    }

    /**
     * The request's website, while it shows and takes reviews.
     *
     * @throws ReviewsOffException 404
     */
    private static function open(Request $request): Website
    {
        $website = StoreMiddleware::website($request);

        return $website->reviews_enabled ? $website : throw new ReviewsOffException();
    }
}
