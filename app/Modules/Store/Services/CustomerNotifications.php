<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Presenters\NotificationPresenter;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Illuminate\Database\Eloquent\Builder;

/**
 * A customer's own notices on the website — every one the shop sent them, wherever it reached them
 * (Notifications\Services\Notices keeps them all): the feed, the unread count, and marking them read.
 */
final class CustomerNotifications
{
    /** The most notices one request marks read by their numbers — a page of the feed, and room to spare. */
    public const READ_MAX = 100;

    private const IDS_REFUSED = 'شناسه‌ها باید لیستی از حداکثر ' . self::READ_MAX . ' شماره اعلان باشد.';

    /** A page of the customer's notices, newest first (only unread ones with `unread`), with the unread count. */
    public function page(User $customer, PageRequest $request): Page
    {
        $query = self::of($customer)->latest('id');
        if (Input::truthy($request->text('unread'))) {
            $query->whereNull('read_at');
        }

        return Page::fetch($query, $request, NotificationPresenter::present(...))->with(['unread' => $this->unread($customer)]);
    }

    /**
     * How many of the customer's notices are unread. Counted off the (user_id, read_at) index alone, without the shop
     * scope: the customer's id already names their shop.
     */
    public function unread(User $customer): int
    {
        return Notification::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('user_id', $customer->id)->whereNull('read_at')->count();
    }

    /**
     * Mark the customer's notices `ids` read (another's id marks nothing, no error), or all of theirs without `ids`, in one
     * UPDATE that keeps an earlier read time. Answers how many are left unread.
     *
     * @param array<string, mixed> $input The request's body: `ids`, a list of numbers, or none
     * @throws ValidationException on `ids`: not a list of numbers, or more than READ_MAX
     */
    public function markRead(User $customer, array $input): int
    {
        $ids = array_key_exists('ids', $input) ? self::ids($input['ids']) : null;
        if ($ids !== []) {
            $unread = self::of($customer)->whereNull('read_at');
            if ($ids !== null) {
                $unread->whereIn('id', $ids);
            }
            $unread->update(['read_at' => now()]);
        }

        return $this->unread($customer);
    }

    /**
     * The ids a request names: a list of whole numbers (Persian digits too), READ_MAX at most.
     *
     * @return list<int>
     * @throws ValidationException on `ids`
     */
    private static function ids(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::READ_MAX) {
            throw ValidationException::on('ids', self::IDS_REFUSED);
        }

        $numbers = [];
        foreach ($ids as $id) {
            $numbers[] = Input::integerOf($id) ?? throw ValidationException::on('ids', self::IDS_REFUSED);
        }

        return $numbers;
    }

    /** @return Builder<Notification> The customer's notices, in the current shop. */
    private static function of(User $customer): Builder
    {
        return Notification::query()->where('user_id', $customer->id);
    }
}
