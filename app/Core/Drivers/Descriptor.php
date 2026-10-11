<?php

declare(strict_types=1);

namespace App\Core\Drivers;

use App\Core\Forms\Form;

/**
 * What the panels show of a driver: its key, Persian label and description, short notes (versions it needs, caveats),
 * its form, and `traits` — what its family says beyond that (a database driver's `installable`, a gateway's `kind`).
 */
final class Descriptor
{
    /**
     * @param list<string> $notes
     * @param array<string, scalar|list<scalar>|null> $traits
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
     * The API's DriverDescription: the driver with its form's fields described.
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
