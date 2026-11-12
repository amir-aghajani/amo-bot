<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * The sign-in forms a website's captcha guards, as the widget names its action: a token solved for one passes no other —
 * a code asked by SMS (PhoneSignIn) is no password sign-in's token.
 * The API description marks each operation's (`x-captcha`).
 */
enum CaptchaAction: string
{
    case SignUp = 'sign_up';
    case SignIn = 'sign_in';
    case PasswordReset = 'password_reset';
    /** A code asked for a mobile number, to sign in by SMS. */
    case PhoneSignIn = 'phone_sign_in';
}
