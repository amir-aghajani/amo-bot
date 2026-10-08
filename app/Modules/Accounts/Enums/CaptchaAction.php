<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * The forms of a sign-in that a website's captcha guards, as the widget names its action — Turnstile's `data-action`,
 * ALTCHA's challenge asked with `?action=` —: a token solved for one passes no other. The Store API's description says
 * each operation's (`x-captcha`).
 */
enum CaptchaAction: string
{
    case SignUp = 'sign_up';
    case SignIn = 'sign_in';
    case PasswordReset = 'password_reset';
}
