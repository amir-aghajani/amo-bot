<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config\Repository;
use PHPUnit\Framework\TestCase;

final class ConfigRepositoryTest extends TestCase
{
    public function testDotNotationAccess(): void
    {
        $config = new Repository(['database' => ['connections' => ['mysql' => ['host' => 'db']]]]);

        self::assertSame('db', $config->get('database.connections.mysql.host'));
        self::assertSame(['host' => 'db'], $config->get('database.connections.mysql'));
        self::assertSame('fallback', $config->get('database.connections.pgsql.host', 'fallback'));
        self::assertNull($config->get('database.connections.mysql.host.port'), 'a value is not a section');
    }

    public function testSetCreatesNestedKeys(): void
    {
        $config = new Repository(['telegram' => 'not a section yet']);
        $config->set('telegram.token', 'abc');
        $config->set('telegram.username', 'amo_bot');

        self::assertSame(['token' => 'abc', 'username' => 'amo_bot'], $config->get('telegram'));
    }
}
