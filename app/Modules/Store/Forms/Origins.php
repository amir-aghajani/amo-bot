<?php

declare(strict_types=1);

namespace App\Modules\Store\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldType;
use App\Core\Http\Origin;

/**
 * The website's other allowed origins (a site being built on http://localhost:3000), sent as a list or typed text (one a
 * line, or comma-separated): each `scheme://host[:port]` and nothing more, kept normalised (Core\Http\Origin), without
 * repeats, `max` at most.
 *
 * @extends Field<list<string>>
 */
final class Origins extends Field
{
    public function __construct(
        string $name,
        string $key,
        private readonly int $max,
    ) {
        parent::__construct($name, $key, []);
    }

    public function type(): FieldType
    {
        return FieldType::Textarea;
    }

    public function read(array $input, mixed $kept): array
    {
        $value = $input[$this->name] ?? null;
        $typed = is_array($value)
            ? array_map(static fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value)
            : (preg_split('/[\s,]+/', is_scalar($value) ? (string) $value : '') ?: []);

        $origins = [];
        $none = [];
        foreach (array_filter($typed, static fn(string $item): bool => $item !== '') as $item) {
            $origin = Origin::normalize($item);
            if ($origin === null) {
                $none[] = '«' . mb_substr($item, 0, 64) . '»';
            } elseif (!in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }
        if ($none !== []) {
            throw new FieldRefused(sprintf('%s %s؛ فقط scheme، دامنه و در صورت نیاز پورت را بنویسید، مثل https://example.com یا http://localhost:3000.', implode('، ', $none), count($none) === 1 ? 'یک Origin نیست' : 'Origin نیستند'));
        }
        if (count($origins) > $this->max) {
            throw new FieldRefused(sprintf('حداکثر %d Origin می‌توانید اضافه کنید.', $this->max));
        }

        return $origins;
    }

    public function cast(mixed $kept): array
    {
        return is_array($kept) ? array_values(array_filter($kept, is_string(...))) : $this->default;
    }
}
