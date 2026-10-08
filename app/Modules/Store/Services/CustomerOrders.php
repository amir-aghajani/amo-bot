<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A customer's orders on the shop's website — their own only, anyone else's not there at all —, each with its payments,
 * read with what they show (RELATIONS: never a query a row).
 */
final class CustomerOrders
{
    /** What an order shows, loaded with it — its payments knowing it as theirs (Order::payments(), chaperoned). */
    private const RELATIONS = ['plan', 'server', 'subscription.server', 'payments.method'];

    public function __construct(private readonly OrderPresenter $presenter) {}

    /**
     * The customer's orders, newest first — in one state (`status`, an OrderStatus) and of one kind (`type`, an
     * OrderType), or all —, a page of them.
     */
    public function page(User $customer, PageRequest $request): Page
    {
        $query = self::of($customer)->latest('id');
        $status = $request->enum('status', OrderStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $type = $request->enum('type', OrderType::class);
        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return Page::fetch($query->with(self::RELATIONS), $request, $this->presenter->present(...));
    }

    /**
     * One of the customer's orders: another's is not there, as one that never was.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(User $customer, int $id): Order
    {
        return self::of($customer)->with(self::RELATIONS)->findOrFail($id);
    }

    /** @return Builder<Order> The customer's orders — in the shop the request is worked in. */
    private static function of(User $customer): Builder
    {
        return Order::query()->where('user_id', $customer->id);
    }
}
