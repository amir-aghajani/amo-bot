<?php

declare(strict_types=1);

namespace App\Core\Drivers;

use App\Core\Forms\Form;

/**
 * What a driver is to the panels: its key, its name and description, the form it asks the admin to fill in (each field
 * described, Form::describe()), short notes under the description (the versions it needs, its caveats) and what its
 * family says of it beyond this (`traits`: a database driver's `installable`, a mail driver's `sender`). Texts are
 * Persian: they are shown as they are.
 */
final class Descriptor
{
    /**
     * @param list<string> $notes
     * @param array<string, scalar|list<scalar>|null> $traits What one family says beyond this (a gateway's kind…)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly Form $form,
        public readonly array $notes = [],
        public readonly array $traits = [],
    ) {}

    /**
     * The driver as the panels show it, its form's fields described.
     *
     * @return array{key: string, label: string, description: string, notes: list<string>, fields: list<array<string, mixed>>, traits: object}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'notes' => $this->notes,
            'fields' => $this->form->describe(),
            'traits' => (object) $this->traits,
        ];
    }
}
