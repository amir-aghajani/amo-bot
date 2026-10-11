<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Core\Exceptions\ValidationException;
use App\Support\Input;
use App\Support\Traffic;

/**
 * Days and traffic support adds to services — a grant (Grants) or one service's extension
 * (SubscriptionActions::extend()) — with the note the customers read and whether they are told.
 */
final class Gift
{
    public const MAX_DAYS = 365;
    public const MAX_GB = 10000;

    public function __construct(
        public readonly int $days,
        public readonly int $bytes,
        /** Support's note to the customers (BotText::AdminNote); null for none. */
        public readonly ?string $note,
        public readonly bool $notify,
    ) {}

    /**
     * Read what the admin typed — {days, traffic_gb, <$noteField>?, notify?}: amounts in Persian digits too, blank for
     * none, each within bounds and at least one above zero; the note by Input::note(); told unless `notify` is off.
     *
     * @param array<string, mixed> $input
     * @param string $noteField Where the form puts the note: a grant's `reason`, a service's `note`
     * @throws ValidationException under each field it refuses — under `grant` when neither amount gives anything
     */
    public static function fromInput(array $input, string $noteField): self
    {
        $errors = [];
        $days = Input::text($input, 'days') === '' ? 0 : Input::integer($input, 'days');
        if ($days === null || $days > self::MAX_DAYS) {
            $errors['days'][] = sprintf('تعداد روز باید عددی بین 0 تا %d باشد.', self::MAX_DAYS);
        }
        $gigabytes = Input::text($input, 'traffic_gb') === '' ? '0' : Input::decimal($input, 'traffic_gb');
        if ($gigabytes === null || (float) $gigabytes > self::MAX_GB) {
            $errors['traffic_gb'][] = sprintf('حجم را به گیگابایت وارد کنید؛ حداکثر %d.', self::MAX_GB);
        }
        $note = null;
        try {
            $note = Input::note($input, $noteField);
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        if ($days === null || $gigabytes === null || $errors !== []) {
            throw new ValidationException($errors);
        }

        $bytes = Traffic::bytesOfGb($gigabytes);
        if ($days === 0 && $bytes === 0) {
            throw ValidationException::on('grant', 'زمان یا حجمی برای افزودن وارد کنید.');
        }

        return new self($days, $bytes, $note, Input::truthy($input['notify'] ?? true));
    }
}
