<?php

declare(strict_types=1);

namespace App\Core\Forms;

/**
 * How a generic form draws a field (Fields\Field::describe()) — a driver's form, which the panels draw from its
 * description alone. Texts are Persian: they are shown as they are.
 */
final class FieldSpec
{
    /**
     * @param array<string, string> $options A choice's values and their words, in order
     * @param array<string, list<string>> $when Shown — and read, and required when it is — only while each named field (one
     *                                          before it in the form) holds one of these values: a switch as "true" or
     *                                          "false", a number as its digits
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
        /** Said after a number: «ثانیه», «گیگابایت» — a number's own unit when there is none here. */
        public readonly ?string $unit = null,
        /** Latin content (a host, a username): drawn left to right. */
        public readonly bool $ltr = false,
    ) {}
}
