<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Staging\Table;
use App\Modules\Imports\Survey\Recognition;
use App\Modules\Imports\Survey\Survey;

/** A migrator that reads the tables it is given and brings nothing: what the sources' tests stage with. */
final class TablesMigrator implements Migrator
{
    /** @param array<string, Table> $tables */
    public function __construct(private readonly array $tables) {}

    /**
     * A bot's tables as the fixtures hold them: its customers, its panels (two secrets), its payments' ids, its settings
     * whole, its purchase codes (one column no dump has) and its channels.
     */
    public static function bot(): self
    {
        return new self([
            'user' => new Table(['id', 'username', 'number', 'Balance', 'User_Status', 'agent', 'register', 'description_blocking']),
            'marzban_panel' => new Table(['name_panel', 'url_panel', 'username_panel', 'password_panel', 'api_key', 'type', 'secret_code'], ['password_panel', 'api_key']),
            'Payment_report' => new Table(['id']),
            'setting' => new Table(),
            'DiscountSell' => new Table(['codeDiscount', 'price', 'limitDiscount', 'usedDiscount', 'time']),
            'channels' => new Table(['link', 'remark']),
        ]);
    }

    public function key(): string
    {
        return 'tables';
    }

    public function describe(): Descriptor
    {
        return new Descriptor('tables', 'Tables', 'The tables it is given.', new Form('tables', []));
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function recognize(Schema $schema): Recognition
    {
        return Recognition::yes();
    }

    public function survey(Staged $staged): Survey
    {
        return new Survey(null, [], '0', []);
    }

    public function customers(Staged $staged, int $after = 0): iterable
    {
        return [];
    }

    public function plans(Staged $staged, int $after = 0): iterable
    {
        return [];
    }

    public function services(Staged $staged, int $after = 0): iterable
    {
        return [];
    }

    public function discountCodes(Staged $staged, int $after = 0): iterable
    {
        return [];
    }

    public function giftCodes(Staged $staged, int $after = 0): iterable
    {
        return [];
    }

    public function channels(Staged $staged, int $after = 0): iterable
    {
        return [];
    }

    public function cards(Staged $staged, int $after = 0): iterable
    {
        return [];
    }
}
