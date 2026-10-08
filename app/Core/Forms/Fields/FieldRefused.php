<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

/** A field refused the form's value: the message goes under it (Form collects them into one ValidationException). */
final class FieldRefused extends \RuntimeException {}
