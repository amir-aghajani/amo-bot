<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Input;
use App\Support\Validation;

/**
 * A customer's names as the website's forms take them (a sign-up, and PATCH /me): a first name of 1 to MAX characters, a
 * last name of at most MAX (blank: none); a name of invisible characters alone is none. An account with Telegram keeps
 * Telegram's names — the bot reads them afresh — so the website cannot change them.
 */
final class AccountNames
{
    /** The longest name a form takes. */
    public const MAX = 64;

    public const FROM_TELEGRAM = 'نام حساب‌های تلگرامی از تلگرام خوانده می‌شود.';

    /**
     * The first name typed under `first_name`, or why it cannot be one under it.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    public static function first(array $input, array &$errors): ?string
    {
        $typed = Input::text($input, 'first_name');
        $name = Customers::profile(null, $typed, null)['first_name'];
        if ($name === null) {
            $errors['first_name'][] = 'نام را وارد کنید.';
        } elseif (mb_strlen($typed) > self::MAX) {
            $errors['first_name'][] = Validation::tooLong('نام', self::MAX);
        }

        return $name;
    }

    /**
     * The last name typed under `last_name` (blank: none), or why it cannot be one under it.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    public static function last(array $input, array &$errors): ?string
    {
        $typed = Input::text($input, 'last_name');
        if (mb_strlen($typed) > self::MAX) {
            $errors['last_name'][] = Validation::tooLong('نام خانوادگی', self::MAX);
        }

        return Customers::profile(null, null, $typed)['last_name'];
    }

    /**
     * Set the names sent, the rest left as they are; refused under each field sent for an account with Telegram.
     *
     * @param array<string, mixed> $input {first_name?, last_name?}
     * @throws ValidationException 422 under the field
     */
    public function rename(User $user, array $input): User
    {
        $sent = array_values(array_intersect(['first_name', 'last_name'], array_keys($input)));
        if ($sent === []) {
            return $user;
        }
        if ($user->telegram_id !== null) {
            throw new ValidationException(array_fill_keys($sent, [self::FROM_TELEGRAM]), self::FROM_TELEGRAM);
        }

        $errors = [];
        $names = [];
        foreach ($sent as $field) {
            $names[$field] = $field === 'first_name' ? self::first($input, $errors) : self::last($input, $errors);
        }
        ValidationException::ifAny($errors);

        $user->forceFill($names)->save();

        return $user;
    }
}
