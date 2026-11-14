<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * How the other bot's data arrives: its backup file, uploaded (the bot's own backup — a zip with its .sql, as Faoxima
 * sends it —, a phpMyAdmin or mysqldump export, either gzipped or zipped), or read off its MySQL database over a
 * connection the owner gives.
 */
enum ImportSource: string
{
    case Backup = 'backup';
    case Database = 'database';
}
