<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The website shows no reviews and takes none (its `reviews_enabled` off, as a website starts): GET and POST /reviews
 * answer a 404, as a closed store's address does.
 */
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
