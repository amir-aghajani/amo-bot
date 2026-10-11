<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Discounts\Services\PersonalDiscounts;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A customer's own discount («تخفیف اختصاصی», Discounts\Services\PersonalDiscounts), set from their page — the panels alone. */
final class CustomerDiscountController extends ApiController
{
    public function __construct(
        private readonly PersonalDiscounts $discounts,
        private readonly UserDirectory $users,
    ) {}

    /**
     * PUT /users/{id}/discount — {percent}: 0 to 100, 0 none; the customer as the users table has them.
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `percent`
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->discounts->set($this->load(User::class, $args), Principal::of($request)->actor(), $this->input($request));

        return $this->json($response, ['user' => $this->users->presentWithCounts($user)]);
    }
}
