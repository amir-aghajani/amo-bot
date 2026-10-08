<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\Urls;
use App\Modules\Accounts\Enums\CaptchaAction;
use App\Modules\Auth\NamedShop;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Store\Enums\StaffGrant;
use App\Modules\Store\Http\StaffMiddleware;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\PSR7\PathFinder;
use Symfony\Component\Yaml\Yaml;
use Tests\HttpTestCase;
use Tests\Support\ApiDescription;
use Tests\TestCase;

/**
 * resources/api/openapi.yaml is the API's contract: the panels' types are generated from it and every request the HTTP
 * tests send, and every response they get back, is checked against it. It must be a valid description, describe every
 * route the app has — and none it does not — and what each change takes. A screen both panels have is described once,
 * under `/api/{panel}/…`: it stands for one route per panel its `panel` parameter names — and, marked `x-staff`, for its
 * route on the shops' websites' admin API too (Support\ApiDescription adds it), exactly the operations routes/api.php
 * mounts there, each asking the grant its marker names.
 */
final class ApiDescriptionTest extends TestCase
{
    /** What a website answers its visitor before they sign in, under its base address: the shop, what it sells, its reviews, the ways in. */
    private const STORE_PUBLIC = [
        '',
        '/plans',
        '/plans/{id}',
        '/status',
        '/reviews',
        '/auth/nonce',
        '/auth/telegram/authorize',
        '/auth/telegram',
        '/auth/google',
        '/auth/register',
        '/auth/register/verify',
        '/auth/login',
        '/auth/login/2fa',
        '/auth/password/forgot',
        '/auth/password/reset',
        '/captcha/challenge',
        '/captcha/verify',
    ];

    /** What anyone does on a website, a signed-in customer as themselves: a review, theirs when their token comes with it. */
    private const STORE_EITHER = ['post /reviews'];

    public function testTheDescriptionIsValidOpenApi(): void
    {
        $description = self::description();

        self::assertTrue($description->validate(), implode("\n", $description->getErrors()));
    }

    public function testEveryRouteIsDescribedAndNothingElse(): void
    {
        $routes = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, '/api/') && $pattern !== '/health') {
                continue;
            }
            // Slim's placeholders carry their pattern ({id:[0-9]+}); OpenAPI's are bare ({id}).
            $path = (string) preg_replace(Urls::PLACEHOLDER, '{$1}', $pattern);
            foreach ($route->getMethods() as $method) {
                $routes[] = strtolower($method) . ' ' . $path;
            }
        }

        $described = [];
        foreach (self::operations() as [$method, $path]) {
            $described[] = $method . ' ' . $path;
        }

        sort($routes);
        sort($described);
        self::assertSame($routes, $described, 'routes/api.php and resources/api/openapi.yaml name the same operations');
    }

    /**
     * What the HTTP tests check an answer against is the operation league/openapi-psr7-validator finds for the request:
     * under each panel, every operation of the description must be found as itself — `/api/agent/plans` as
     * `/api/{panel}/plans`, an owner's `/api/admin/servers/1` as its own — never as another that happens to match.
     */
    public function testTheValidatorFindsEveryOperationUnderEachPanel(): void
    {
        $description = self::description();

        foreach (self::operations() as [$method, $path, $described]) {
            // Any value matches a placeholder ({id}, {group}, {key}…): the validator reads the template, not the schema.
            $request = (string) preg_replace('/\{\w+\}/', '1', $path);
            $found = array_map(static fn($address): string => $address->path(), (new PathFinder($description, $request, $method))->search());

            self::assertSame($described, $found[0] ?? null, "{$method} {$request}");
        }
    }

    /** Every `{panel}` path names its panels in its path-level `panel` parameter, which the tests above read. */
    public function testEverySharedPathDeclaresItsPanels(): void
    {
        foreach (self::description()->paths->getPaths() as $path => $item) {
            if (str_contains($path, '{panel}')) {
                self::assertSame(['admin', 'agent'], self::panels($item), $path);
            }
        }
    }

    /**
     * Every request of the owner's panel names the shop its tab shows (`X-Shop`, Auth\NamedShop): every path under
     * /api/admin and every shared /api/{panel} path takes it, at the path's level — no agent's own path, no website's.
     * What the panel draws or downloads by its address — an answer that is no JSON: a receipt, a picture, an animation
     * — carries no header, so it takes the shop in its query too.
     */
    public function testEveryPathOfTheOwnersPanelTakesTheShopItsRequestNames(): void
    {
        $media = 0;
        foreach (self::description()->paths->getPaths() as $path => $item) {
            $owners = str_starts_with($path, '/api/admin/') || str_starts_with($path, '/api/{panel}/');
            $shop = array_filter($item->parameters, static fn($parameter): bool => $parameter instanceof Parameter && $parameter->in === 'header' && $parameter->name === NamedShop::HEADER);
            self::assertCount($owners ? 1 : 0, $shop, $path);

            $read = $item->get;
            $answer = $read?->responses?->getResponse('200');
            if (!$owners || $read === null || $answer === null || isset($answer->content['application/json'])) {
                continue;
            }
            $media++;
            $query = array_filter($read->parameters, static fn($parameter): bool => $parameter instanceof Parameter && $parameter->in === 'query' && $parameter->name === NamedShop::QUERY);
            self::assertCount(1, $query, "GET {$path}: what the panel draws by its address names its shop in the query");
        }
        self::assertGreaterThanOrEqual(5, $media);
    }

    /**
     * Every path of the Store API is under a store key its path-level `store` parameter describes (24 lower-case hex
     * characters), and what a customer reads or does for themselves — anything but the shop, what it sells, its reviews
     * and the ways in (STORE_PUBLIC) — says it takes their bearer token; what anyone does, a customer as themselves
     * (STORE_EITHER), says it takes none or theirs.
     */
    public function testEveryStorePathDeclaresItsKeyAndACustomersOwnTheirToken(): void
    {
        $paths = 0;
        foreach (self::description()->paths->getPaths() as $path => $item) {
            if (!str_starts_with($path, '/api/store/')) {
                continue;
            }
            $paths++;
            self::assertStringStartsWith('/api/store/v1/{store}', $path);
            $store = array_values(array_filter($item->parameters, static fn($parameter): bool => $parameter instanceof Parameter && $parameter->name === 'store'));
            self::assertCount(1, $store, $path);
            self::assertSame(['path', '^[0-9a-f]{24}$'], [$store[0]->in, $store[0]->schema instanceof Schema ? $store[0]->schema->pattern : null], $path);

            foreach ($item->getOperations() as $method => $operation) {
                $route = substr($path, strlen('/api/store/v1/{store}'));
                $customers = !in_array($route, self::STORE_PUBLIC, true);
                $either = in_array("{$method} {$route}", self::STORE_EITHER, true);
                $security = array_map(static fn($requirement): array => (array) $requirement->getSerializableData(), $operation->security ?? []);
                self::assertSame($customers ? [['customer' => []]] : ($either ? [[], ['customer' => []]] : []), $security, "{$method} {$path}");
            }
        }
        self::assertGreaterThan(5, $paths);
    }

    /**
     * What a change takes is part of the contract: every POST, PUT and PATCH either describes its body — a named schema,
     * one type the panels' are generated as, which every HTTP test's request is held to — or says it takes none
     * (`x-no-body: true`), and never both, so a new operation cannot leave what it reads undescribed.
     */
    public function testEveryChangeDescribesItsBodyOrSaysItTakesNone(): void
    {
        foreach (self::description()->paths->getPaths() as $path => $item) {
            foreach ($item->getOperations() as $method => $operation) {
                if (!in_array($method, ['post', 'put', 'patch'], true)) {
                    continue;
                }
                $body = $operation->requestBody;
                $bodyless = ($operation->getExtensions()['x-no-body'] ?? null) === true;
                self::assertNotSame($bodyless, $body !== null, "{$method} {$path}: a requestBody, or x-no-body: true — one of them");
                foreach ($body instanceof RequestBody ? $body->content : [] as $type => $media) {
                    // A $ref, once read, is the schema it names — where it sits says which.
                    $schema = $media instanceof MediaType ? $media->schema : null;
                    self::assertMatchesRegularExpression('~^/components/schemas/\w+$~', (string) $schema?->getDocumentPosition()?->getPointer(), "{$method} {$path}: its {$type} body is a named schema");
                }
            }
        }
    }

    /**
     * A body that carries a captcha's token (`captcha`, the widget's string) says the action its widget must name
     * (`x-captcha`: a sign-in form's CaptchaAction, or a review's — Reviews::CAPTCHA_ACTION), and only such a body does —
     * each form the captcha guards once: a website's developer reads there which action each form's widget names.
     */
    public function testEveryBodyThatCarriesACaptchaSaysItsAction(): void
    {
        $actions = [...array_map(static fn(CaptchaAction $action): string => $action->value, CaptchaAction::cases()), Reviews::CAPTCHA_ACTION];
        $marked = [];
        foreach (self::description()->paths->getPaths() as $path => $item) {
            foreach ($item->getOperations() as $method => $operation) {
                $carries = false;
                foreach ($operation->requestBody instanceof RequestBody ? $operation->requestBody->content : [] as $media) {
                    $schema = $media instanceof MediaType ? $media->schema : null;
                    $token = $schema instanceof Schema ? $schema->properties['captcha'] ?? null : null;
                    $carries = $carries || ($token instanceof Schema && $token->type === 'string');
                }
                $action = $operation->getExtensions()['x-captcha'] ?? null;
                self::assertSame($carries, $action !== null, "{$method} {$path}: a body with a `captcha` says its x-captcha, and only such a body");
                if ($action !== null) {
                    self::assertContains($action, $actions, "{$method} {$path}");
                    $marked[] = $action;
                }
            }
        }
        self::assertEqualsCanonicalizing($actions, $marked, 'every form a captcha guards, once');
    }

    /**
     * The shops' websites' admin API (`/api/store/v1/{store}/admin/…`, Store\Http\StaffMiddleware) is the operations the
     * file marks `x-staff` and nothing else, each route asking the grant its marker names (StaffGrant::ARGUMENT; `true`
     * for none) and a recent sign-in without one where it is marked `x-staff-recent` (StaffMiddleware::RECENT_SIGN_IN) —
     * so an operation mounted for the shop's admins is described as theirs, and neither a grant nor a recent sign-in is
     * ever the route's alone.
     */
    public function testTheWebsitesAdminsHaveExactlyTheMarkedOperationsWithTheirGrants(): void
    {
        $routes = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            $path = (string) preg_replace(Urls::PLACEHOLDER, '{$1}', $route->getPattern());
            if (str_starts_with($path, ApiDescription::STAFF . '/')) {
                foreach ($route->getMethods() as $method) {
                    $routes[] = strtolower($method) . ' ' . $path . ' ' . ($route->getArgument(StaffGrant::ARGUMENT) ?? 'true') . ($route->getArgument(StaffMiddleware::RECENT_SIGN_IN) !== null ? ' recent' : '');
                }
            }
        }

        $marked = [];
        foreach (Yaml::parseFile(ApiDescription::FILE)['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                $marker = is_array($operation) ? $operation[ApiDescription::MARKER] ?? null : null;
                if ($marker !== null) {
                    $marked[] = $method . ' ' . ApiDescription::STAFF . substr($path, strlen(ApiDescription::PANELS)) . ' ' . ($marker === true ? 'true' : $marker) . (($operation[ApiDescription::RECENT] ?? false) === true ? ' recent' : '');
                }
            }
        }

        sort($routes);
        sort($marked);
        self::assertGreaterThan(50, count($routes));
        self::assertContains('post ' . ApiDescription::STAFF . '/payments/{id}/approve true recent', $routes, 'approving a payment asks a recent sign-in');
        self::assertSame($routes, $marked, 'routes/api.php mounts for the shops\' admins exactly what resources/api/openapi.yaml marks x-staff, with its grant and its recent sign-in');
    }

    /**
     * Only an operation both panels have is marked `x-staff` — what one panel alone has (the owner's servers, the agent's
     * account) is never a shop admin's —, its marker is `true` or a grant there is, and `x-staff-recent` marks one of
     * them that names no grant (a granted one asks a recent sign-in anyway).
     */
    public function testOnlyThePanelsSharedOperationsAreMarkedAndWithAGrantThereIs(): void
    {
        $grants = array_map(static fn(StaffGrant $grant): string => $grant->value, StaffGrant::cases());
        foreach (Yaml::parseFile(ApiDescription::FILE)['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                $marker = is_array($operation) ? $operation[ApiDescription::MARKER] ?? null : null;
                $recent = is_array($operation) ? $operation[ApiDescription::RECENT] ?? null : null;
                self::assertTrue($recent === null || ($recent === true && $marker === true), "{$method} {$path}: x-staff-recent is true, on an operation x-staff marks without a grant");
                if ($marker === null) {
                    continue;
                }
                self::assertStringStartsWith(ApiDescription::PANELS . '/', $path, "{$method} {$path}");
                self::assertTrue($marker === true || in_array($marker, $grants, true), "{$method} {$path}: x-staff is true or a StaffGrant");
            }
        }
    }

    /**
     * Every operation of the description as the app routes it: [method, path, the description's own path] — a
     * `{panel}` path once per panel.
     *
     * @return list<array{string, string, string}>
     */
    private static function operations(): array
    {
        $operations = [];
        foreach (self::description()->paths->getPaths() as $described => $item) {
            $paths = str_contains($described, '{panel}')
                ? array_map(static fn(string $panel): string => str_replace('{panel}', $panel, $described), self::panels($item))
                : [$described];
            foreach ($paths as $path) {
                foreach (array_keys($item->getOperations()) as $method) {
                    $operations[] = [$method, $path, $described];
                }
            }
        }

        return $operations;
    }

    /** @return list<string> The panels a path's `panel` parameter names (none: an empty list). */
    private static function panels(PathItem $item): array
    {
        foreach ($item->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->name === 'panel' && $parameter->in === 'path' && $parameter->schema instanceof Schema) {
                return array_values(array_map(strval(...), $parameter->schema->enum));
            }
        }

        return [];
    }

    /** The description the HTTP tests check every answer against: read once a run. */
    private static function description(): OpenApi
    {
        return HttpTestCase::apiDescription();
    }
}
