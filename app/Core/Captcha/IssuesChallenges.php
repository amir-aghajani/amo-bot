<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/** A captcha whose widget fetches its challenge from the shop (the Store API's GET /captcha/challenge): ALTCHA. */
interface IssuesChallenges
{
    /**
     * A new challenge in the widget's own shape, bound to `$action` when named so its solution passes no other form.
     *
     * @param array<string, mixed> $values Its form's values
     * @return array<string, mixed>
     */
    public function challenge(array $values, ?string $action): array;
}
