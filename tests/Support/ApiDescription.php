<?php

declare(strict_types=1);

namespace Tests\Support;

use cebe\openapi\json\JsonPointer;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use Symfony\Component\Yaml\Yaml;

/**
 * resources/api/openapi.yaml as the API is: the file describes a screen of the shop's daily work once, under
 * `/api/{panel}/…`, and marks each operation the shop's admins also have on its website (`x-staff: true`, or the grant
 * the shop must give for it — Store\Enums\StaffGrant); this reads the file and adds, for every marked operation, its
 * path under the website's admin API (STAFF): the website's store key in place of the panel, no `X-Shop` (the key names
 * the shop), the `customer` bearer scheme, its 401 (`SignedOut`) and its 403 (`StaffRefused`) — the one place the
 * admins' paths are made, which the HTTP tests hold every request and answer to (HttpTestCase) and the documentation is
 * written from.
 */
final class ApiDescription
{
    public const FILE = __DIR__ . '/../../resources/api/openapi.yaml';

    /** Where the panels' shared operations are described. */
    public const PANELS = '/api/{panel}';

    /** Where the shop's admins have them on its website. */
    public const STAFF = '/api/store/v1/{store}/admin';

    /** The marker of an operation the shop's admins have: true, or the grant it asks. */
    public const MARKER = 'x-staff';

    /** The marker of one that asks them a recent sign-in without a grant (a granted one asks it anyway): approving a payment. */
    public const RECENT = 'x-staff-recent';

    /** The parameters a panel's path names that an admin's does not have (the panel, the shop the owner's tab names), and the one in their place. */
    private const PANEL = '#/components/parameters/Panel';
    private const SHOP = '#/components/parameters/Shop';
    private const SHOP_QUERY = '#/components/parameters/ShopQuery';
    private const STORE = '#/components/parameters/Store';

    private static ?OpenApi $read = null;

    /** The description, its admins' paths added, its references resolved: read once a run. */
    public static function openApi(): OpenApi
    {
        if (self::$read === null) {
            $file = (string) realpath(self::FILE);
            $spec = new OpenApi(self::expanded());
            $spec->setReferenceContext(new ReferenceContext($spec, $file));
            $spec->setDocumentContext($spec, new JsonPointer(''));
            $spec->resolveReferences();
            self::$read = $spec;
        }

        return self::$read;
    }

    /**
     * The file as an array, every marked operation's admins' path added.
     *
     * @return array<string, mixed>
     */
    public static function expanded(): array
    {
        $description = Yaml::parseFile((string) realpath(self::FILE));
        $staff = [];
        foreach ($description['paths'] as $path => $item) {
            if (!str_starts_with($path, self::PANELS . '/')) {
                continue;
            }
            $operations = array_filter($item, static fn(mixed $operation, string $key): bool => $key !== 'parameters' && is_array($operation) && isset($operation[self::MARKER]), ARRAY_FILTER_USE_BOTH);
            if ($operations === []) {
                continue;
            }
            $staff[self::STAFF . substr($path, strlen(self::PANELS))] = ['parameters' => self::parameters($item['parameters'] ?? [])] + array_map(self::operation(...), $operations);
        }
        $description['paths'] += $staff;

        return $description;
    }

    /**
     * A panel's path's parameters as its admins' path has them: the store key in the panel's place, the shop the owner's
     * tab names left out.
     *
     * @param list<array<string, mixed>> $parameters
     * @return list<array<string, mixed>>
     */
    private static function parameters(array $parameters): array
    {
        $kept = [];
        foreach ($parameters as $parameter) {
            $ref = $parameter['$ref'] ?? null;
            if ($ref !== self::SHOP) {
                $kept[] = $ref === self::PANEL ? ['$ref' => self::STORE] : $parameter;
            }
        }

        return $kept;
    }

    /**
     * An operation as the shop's admins have it: their bearer token, its refusals, no shop named in its query.
     *
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private static function operation(array $operation): array
    {
        $parameters = array_values(array_filter($operation['parameters'] ?? [], static fn(array $parameter): bool => ($parameter['$ref'] ?? null) !== self::SHOP_QUERY));
        unset($operation['parameters']);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        $operation['security'] = [['customer' => []]];
        $operation['responses'] += [
            '401' => ['$ref' => '#/components/responses/SignedOut'],
            '403' => ['$ref' => '#/components/responses/StaffRefused'],
        ];

        return $operation;
    }
}
