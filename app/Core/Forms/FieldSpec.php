<?php

declare(strict_types=1);

namespace App\Core\Forms;

/** How a generic form draws a field: its Persian words, where it goes, and when it is shown. */
final class FieldSpec
{
    /**
     * @param array<int|string, string> $options A choice's values and their labels, in order
     * @param array<string, list<string>> $when Shown (and so read and required) only while each named field before it
     *                                          holds one of these values: a switch as "true"/"false", a number as digits
     */
    public function __construct(
        public readonly string $label,
        /** The line under it. */
        public readonly ?string $hint = null,
        public readonly ?string $placeholder = null,
        /** Drawn under «تنظیمات پیشرفته»: rarely needed. */
        public readonly bool $advanced = false,
        public readonly array $options = [],
        public readonly array $when = [],
        /** Shown after a number («ثانیه»); defaults to the field's own unit. */
        public readonly ?string $unit = null,
        /** Latin content (a host, a username): drawn left to right. */
        public readonly bool $ltr = false,
    ) {}
}
