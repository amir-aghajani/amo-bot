<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Input;
use App\Support\Validation;

/**
 * A customer's names as the website's forms take them — a sign-up's, and the account's own (PATCH /me, rename()): the
 * first name 1 to MAX characters, the last name MAX at most (blank: none), a name made only of invisible characters none
 * (Customers::profile()). A Telegram account's names are Telegram's: the bot reads them afresh as they write to it, so
 * the website does not change them.
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
     * The last name typed under `last_name` — blank: none —, or why it cannot be one under it.
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
     * The account's names, as the customer sets them on the website: the ones sent, the rest as they are — refused
     * under each one sent for an account with Telegram, whose names are Telegram's.
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
