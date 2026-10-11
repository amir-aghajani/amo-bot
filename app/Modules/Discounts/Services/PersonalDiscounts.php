<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Log\LoggerInterface;

/**
 * A customer's own discount («تخفیف اختصاصی», `users.discount_percent`): a lasting percent off what they buy for
 * themselves, one of the automatic discounts. Set from the panels' customer page; 0 removes it.
 */
final class PersonalDiscounts
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Sets the percent and logs who set it.
     *
     * @param array<string, mixed> $input {percent}
     * @throws ValidationException 422 on `percent`
     */
    public function set(User $user, Actor $actor, array $input): User
    {
        $percent = Input::wholeOf($input['percent'] ?? null);
        if ($percent === null || $percent > 100) {
            throw ValidationException::on('percent', 'درصد تخفیف باید عددی بین 0 تا 100 باشد؛ 0 یعنی بدون تخفیف.');
        }

        $user->forceFill(['discount_percent' => $percent])->save();
        $this->logger->info('Customer #{customer} given a discount of {percent}% by {reviewer}', ['customer' => $user->id, 'percent' => $percent, 'reviewer' => $actor->reviewer]);

        return $user;
    }
}
