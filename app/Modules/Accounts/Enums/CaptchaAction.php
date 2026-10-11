<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * The sign-in forms a website's captcha guards, as the widget names its action: a token solved for one passes no other.
 * The API description marks each operation's (`x-captcha`).
 */
enum CaptchaAction: string
{
    case SignUp = 'sign_up';
    case SignIn = 'sign_in';
    case PasswordReset = 'password_reset';
}
