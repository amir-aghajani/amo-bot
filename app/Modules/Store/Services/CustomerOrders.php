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

/** A customer's own orders on the website (another's is a 404), each with its payments, read with RELATIONS in a few queries. */
final class CustomerOrders
{
    /** What an order shows, loaded with it; its payments know it as theirs (chaperoned), so none reads it again. */
    private const RELATIONS = ['plan', 'server', 'subscription.server', 'payments.method'];

    public function __construct(private readonly OrderPresenter $presenter) {}

    /** A page of the customer's orders, newest first, narrowed by `status` and `type` when named. */
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
     * One of the customer's orders; another's is a 404.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(User $customer, int $id): Order
    {
        return self::of($customer)->with(self::RELATIONS)->findOrFail($id);
    }

    /** @return Builder<Order> The customer's orders, in the current shop. */
    private static function of(User $customer): Builder
    {
        return Order::query()->where('user_id', $customer->id);
    }
}
