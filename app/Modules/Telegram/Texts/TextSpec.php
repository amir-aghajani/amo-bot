<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/** One bot text as TextCatalog describes it: its group, kind, title, where it shows, default wording and variables. */
final readonly class TextSpec
{
    /**
     * @param array<string, string|null> $variables name => this text's own description, null for TextCatalog::VARIABLES'
     * @param list<string> $required the variables the text cannot do without (a delivery without its link)
     */
    public function __construct(
        public string $group,
        public TextKind $kind,
        public string $title,
        public string $description,
        public string $default,
        public array $variables = [],
        public array $required = [],
    ) {}
}
