<?php

declare(strict_types=1);

namespace App\Modules\Users\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * No picture is taken now — a receipt, a ticket's —: the host's disk has less room left than the shop keeps free
 * (Users\Services\CustomerPictures::ROOM), until its owner makes room; or the shop's uploads took their day
 * (CustomerPictures::DAY_MEGABYTES), until the day is over. A 503, by decision, as every "the host cannot do this now"
 * of the shop is (a storage/ the install key cannot be written to, the shop's email not set up): the request was fine
 * and may go through later as it is, nothing in it is to change; the panels word a 503 in the server's own words, and a
 * website reads it as the shop's passing trouble. Not a 507: WebDAV's status, which a website's HTTP client and the
 * panels read as no more than an unknown server failure. No Retry-After: the room comes back when the owner makes it,
 * and a day's budget is the shop's whole, not this customer's wait.
 */
final class StorageFullException extends DomainRuleException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /** The disk is (all but) full. */
    public static function disk(): self
    {
        return new self('فضای ذخیره سرور پر شده و فعلا تصویری پذیرفته نمی‌شود؛ کمی بعد دوباره امتحان کنید.');
    }

    /** The shop's uploads took their day. */
    public static function today(): self
    {
        return new self('امروز بیش از این تصویری پذیرفته نمی‌شود؛ چند ساعت دیگر دوباره بفرستید.');
    }

    public function status(): int
    {
        return 503;
    }
}
