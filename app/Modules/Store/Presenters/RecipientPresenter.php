<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Users\Models\User;

/**
 * Another customer of the shop as a customer's website shows them — whom a service («انتقال سرویس») or money («انتقال
 * موجودی») goes, or went, to: their Telegram name and handle, nothing else of their account.
 */
final class RecipientPresenter
{
    /** @return array{name: string|null, username: string|null} */
    public static function present(User $recipient): array
    {
        return ['name' => $recipient->name(), 'username' => $recipient->handle()];
    }
}
