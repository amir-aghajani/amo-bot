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
 * A customer's notices on the shop's website — what the shop told them, wherever it reached them (Notifications\Services\
 * Notices keeps every one) —, their own only: the feed, newest first, a page at a time; what they have not read; and
 * marking them read.
 */
final class CustomerNotifications
{
    /** The most notices one request marks read by their numbers — a page of the feed, and room to spare. */
    public const READ_MAX = 100;

    private const IDS_REFUSED = 'شناسه‌ها باید لیستی از حداکثر ' . self::READ_MAX . ' شماره اعلان باشد.';

    /** The customer's notices, newest first — only those they have not read with `unread` (true) —, a page of them, and how many are unread. */
    public function page(User $customer, PageRequest $request): Page
    {
        $query = self::of($customer)->latest('id');
        if (Input::truthy($request->text('unread'))) {
            $query->whereNull('read_at');
        }

        return Page::fetch($query, $request, NotificationPresenter::present(...))->with(['unread' => $this->unread($customer)]);
    }

    /**
     * How many of the customer's notices they have not read. Their number names their shop already: counted off the
     * (user_id, read_at) index alone, the shop's scope left aside.
     */
    public function unread(User $customer): int
    {
        return Notification::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('user_id', $customer->id)->whereNull('read_at')->count();
    }

    /**
     * The customer's notices numbered `ids` read — another's number is no error, it marks nothing —, every one of theirs
     * without `ids`: one UPDATE, a notice read before keeping when it was. What is left unread is the answer.
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
     * The notices' numbers a request names: a list of whole numbers (Persian digits, or their text, too), READ_MAX at most.
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

    /** @return Builder<Notification> The customer's notices — in the shop the request is worked in. */
    private static function of(User $customer): Builder
    {
        return Notification::query()->where('user_id', $customer->id);
    }
}
