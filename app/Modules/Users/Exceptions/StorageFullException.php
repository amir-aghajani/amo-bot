<?php

declare(strict_types=1);

namespace App\Modules\Users\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * No picture is taken now: the host's disk is nearly full (CustomerPictures::ROOM), or the shop's uploads used up their
 * day (DAY_MEGABYTES). A 503 — the shop's status for "the host cannot do this now": the request itself is
 * fine (507 is WebDAV's and clients read it as an unknown failure). No Retry-After: the owner frees the room.
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
