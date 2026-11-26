<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Application;
use Tests\Support\ApiDescription;
use Tests\TestCase;

/**
 * The code's version said the same where others read it: the API description (resources/api/openapi.yaml) a website's
 * own tools are written from is of the version the code is.
 */
final class VersionTest extends TestCase
{
    public function testTheApiDescriptionIsOfTheCodesVersion(): void
    {
        $info = ApiDescription::read()['info'] ?? null;

        self::assertIsArray($info);
        self::assertSame(Application::VERSION, $info['version'] ?? null, 'info.version of resources/api/openapi.yaml');
    }
}
