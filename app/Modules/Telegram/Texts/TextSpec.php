<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * One text the bot sends, as the catalog describes it: where it belongs and shows, the shop's own wording,
 * and the %variables% the code fills in it — each with the dictionary's meaning unless this text words it
 * its own way (TextCatalog::VARIABLES).
 */
final readonly class TextSpec
{
    /**
     * @param array<string, string|null> $variables name => this text's own description, null for the dictionary's
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
