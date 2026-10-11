<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The website shows and takes no reviews (`reviews_enabled` off, the default): GET and POST /reviews are a 404. */
final class ReviewsOffException extends DomainRuleException
{
    public const MESSAGE = 'این وب‌سایت نظرات مشتریان را نشان نمی‌دهد و نظری نمی‌گیرد.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function status(): int
    {
        return 404;
    }
}
