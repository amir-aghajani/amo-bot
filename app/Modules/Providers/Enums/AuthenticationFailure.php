<?php

declare(strict_types=1);

namespace App\Modules\Providers\Enums;

/**
 * Why a panel that answered would not let the shop in — the two admins mix up most first: a token the panel does not
 * know, and a token without the rights the shop needs.
 */
enum AuthenticationFailure: string
{
    /** Neither a token nor a username and password is set. */
    case Missing = 'missing';
    /** The token is unknown, switched off or expired. */
    case Token = 'token';
    /** The token is valid but may not call the endpoint. */
    case Scope = 'scope';
    /** The username and password were refused. */
    case Login = 'login';
    /** The login wants a one-time code the shop could not give. */
    case TwoFactor = 'two_factor';
    /** A fresh session was refused right after the login. */
    case Session = 'session';
    /** The login form refused the request: its session cookie or CSRF token did not reach it. */
    case Csrf = 'csrf';
}
