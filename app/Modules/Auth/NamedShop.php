<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Exceptions\ValidationException;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shop a panel request names by its bot's id: the `X-Shop` header, or on a read the `shop` query parameter (an
 * `<img>` or a download link carries no header). The shop is each request's, never the session's — every tab of a
 * browser shares one session — so each tab works in the shop its address shows. None named: the main shop.
 */
final class NamedShop
{
    public const HEADER = 'X-Shop';
    public const QUERY = 'shop';

    /** The field a refusal of the shop named is under. */
    public const FIELD = 'shop';

    /** Not a bot's id, or two different ones in the header and the query. */
    public const MALFORMED = 'فروشگاهی که این درخواست نام برده، شماره درستی ندارد.';

    /** A change names its shop in the header alone. */
    public const QUERY_ON_A_CHANGE = 'درخواستی که چیزی را تغییر می‌دهد فروشگاهش را فقط در هدر X-Shop نام می‌برد.';

    private const READS = ['GET', 'HEAD'];

    /**
     * The id of the bot whose shop the request names; null when it names none.
     *
     * @throws ValidationException 422 on `shop`: no bot's id, two that differ, or a change that names it in its query
     */
    public static function of(ServerRequestInterface $request): ?int
    {
        $header = trim($request->getHeaderLine(self::HEADER));
        $query = $request->getQueryParams()[self::QUERY] ?? null;
        if ($query !== null && !in_array($request->getMethod(), self::READS, true)) {
            throw ValidationException::on(self::FIELD, self::QUERY_ON_A_CHANGE);
        }

        $ids = [];
        foreach (array_filter([$header === '' ? null : $header, $query], static fn(mixed $named): bool => $named !== null) as $named) {
            // What is no bot's id counts as 0, which no bot has: refused below with two ids that differ.
            $ids[Input::integerOf($named) ?? 0] = true;
        }
        if (count($ids) > 1 || isset($ids[0])) {
            throw ValidationException::on(self::FIELD, self::MALFORMED);
        }

        return array_key_first($ids);
    }
}
