<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Exceptions\ValidationException;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shop a panel request names, by its bot's id. The owner's panel names the shop its tab shows on every request — in
 * the `X-Shop` header; a read (GET, HEAD) may name it in its `shop` query parameter instead, since what a picture, a
 * video or a download link asks for carries no header —, so nothing on the server remembers a shop between two requests
 * and each tab of one session works in its own. A request that names none is the main shop's. An agent's panel names
 * none: their shop is their bot (AgentAuth refuses another).
 */
final class NamedShop
{
    public const HEADER = 'X-Shop';
    public const QUERY = 'shop';

    /** The field a refusal of the shop named is under. */
    public const FIELD = 'shop';

    /** Not a bot's id — or two of them, one in the header and another in the query. */
    public const MALFORMED = 'فروشگاهی که این درخواست نام برده، شماره درستی ندارد.';

    /** A change names its shop in the header alone: the address it is sent to says nothing of one. */
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
