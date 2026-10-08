<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Manual;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Persian;

/**
 * How long a receipt sent in the bot to a card method may wait for a person before it is accepted on its own
 * (AutoApproveReceiptsTask): whole minutes, a week at most — left blank or out, 0: only by hand. A row kept before
 * the window existed reads 0 too.
 *
 * @extends Field<int>
 */
final class ReviewWindow extends Field
{
    /** What a card's form sends it as, and its row keeps it under. */
    public const NAME = 'auto_approve_after';

    /** A week: longer than that and "automatic" no longer means anything to the customer. */
    private const MAX_MINUTES = 7 * 24 * 60;

    public function __construct(?FieldSpec $spec = null)
    {
        parent::__construct(self::NAME, self::NAME, 0, $spec);
    }

    /**
     * The window a card method's settings keep.
     *
     * @param array<string, mixed> $config The row's (payment_methods.config)
     */
    public static function of(array $config): int
    {
        return (new self())->cast($config[self::NAME] ?? null);
    }

    public function type(): FieldType
    {
        return FieldType::Number;
    }

    public function read(array $input, mixed $kept): int
    {
        // Blank is by hand; anything that is not a whole number of minutes within the week is refused, never ignored.
        if (Input::text($input, $this->name) === '') {
            return 0;
        }
        $minutes = Input::integer($input, $this->name);
        if ($minutes === null || $minutes > self::MAX_MINUTES) {
            throw new FieldRefused('مهلت تایید خودکار باید بر حسب دقیقه و حداکثر ' . Persian::minutes(self::MAX_MINUTES) . ' باشد؛ 0 یعنی فقط تایید دستی.');
        }

        return $minutes;
    }

    public function cast(mixed $kept): int
    {
        $minutes = Input::integerOf($kept);

        return $minutes !== null && $minutes <= self::MAX_MINUTES ? $minutes : 0;
    }

    protected function bounds(): array
    {
        return [0, self::MAX_MINUTES];
    }

    protected function unit(): string
    {
        return 'دقیقه';
    }
}
