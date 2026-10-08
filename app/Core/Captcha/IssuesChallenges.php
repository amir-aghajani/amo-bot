<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/** A captcha whose widget asks the shop for its challenge (the Store API's GET /captcha/challenge): ALTCHA's. */
interface IssuesChallenges
{
    /**
     * A new challenge for the widget to solve, in the widget's own shape — bound to `$action` when one is named, so its
     * solution passes no form of another action.
     *
     * @param array<string, mixed> $values Its form's values (Form::values())
     * @return array<string, mixed>
     */
    public function challenge(array $values, ?string $action): array;
}
