<?php

declare(strict_types=1);

/*
 * The documentation's tool. docs/ is the one tree the repository's own view, GitHub's wiki and GitHub Pages (Docsify,
 * docs/index.html) all read: flat Markdown pages, one a subject, linked to each other as `[words](Page-Name.md)` or
 * `(Page-Name.md#anchor)`.
 *
 *   php scripts/docs.php               # write what is generated from the code: the config.php and bot texts references,
 *                                      # the API's references and docs/openapi.json
 *   php scripts/docs.php --check       # fail while a generated page is stale, or a link or a page is wrong (CI runs it)
 *   php scripts/docs.php --wiki <dir>  # write the tree as GitHub's wiki has it into a checkout of <repo>.wiki
 *
 * A generated page says so at its top and is never edited by hand: change what it is made from, then run the script.
 * The check also follows every link between pages — to the page and to its heading, under the anchors GitHub and Docsify
 * both give it —, holds every page to its name's form and to the sidebar, and every link of README.md, CONTRIBUTING.md,
 * SECURITY.md and CHANGELOG.md.
 * The wiki's form is the same pages with their links to each other written without `.md`; .github/workflows/wiki.yml
 * publishes it.
 */

use App\Core\Application;
use App\Core\Config\ConfigKeys;
use App\Core\Drivers\Driver;
use App\Core\Drivers\Registry;
use App\Core\Forms\Fields\Field;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\TextCatalog;
use App\Modules\Telegram\Texts\TextKind;
use DI\Container;
use Tests\Support\ApiDescription;

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
$docs = $root . '/docs';
$options = getopt('', ['check', 'wiki:']) ?: [];

if (isset($options['check'])) {
    $problems = [...stalePages($root, $docs), ...linkProblems($root, $docs)];
    foreach ($problems as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    if ($problems !== []) {
        fwrite(STDERR, sprintf("\n%d problem(s) in the documentation.\n", count($problems)));
        exit(1);
    }
    fwrite(STDOUT, "The documentation is current, and every link leads somewhere.\n");
    exit(0);
}

if (isset($options['wiki'])) {
    if (!is_string($options['wiki'])) {
        fail('--wiki takes one folder: the checkout of the wiki.');
    }
    exportWiki($root, $docs, $options['wiki']);
    exit(0);
}

foreach (generatedPages($root) as $name => $markdown) {
    if (readPage($docs . '/' . $name) !== $markdown) {
        file_put_contents($docs . '/' . $name, $markdown);
        fwrite(STDOUT, "Wrote docs/{$name}\n");
    }
}

// --- the generated pages ---------------------------------------------------------------------------------------------

/**
 * Every file written from the code, by its name in docs/. Each generator answers the files it writes.
 *
 * @return array<string, string>
 */
function generatedPages(string $root): array
{
    require_once $root . '/vendor/autoload.php';

    $container = bootApplication($root)->container();

    return [
        ...configReference($container),
        ...botTextsReference(),
        ...apiReference(),
    ];
}

/**
 * The app as the generators read it: booted on a configuration of its own — an in-memory SQLite database, a throwaway
 * key —, never on this machine's config.php nor its database.
 */
function bootApplication(string $root): Application
{
    $config = tempnam(sys_get_temp_dir(), 'amobot-docs-');
    if ($config === false) {
        fail('Could not make a scratch config.php in the system\'s temporary folder.');
    }
    $settings = [
        'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'LOG_LEVEL' => 'error',
    ];
    file_put_contents($config, '<?php return ' . var_export($settings, true) . ";\n");

    try {
        return Application::boot($root, $config);
    } finally {
        unlink($config);
    }
}

/**
 * docs/Config-Reference.md: every setting config.php may hold, by section, as the file lists them (ConfigKeys::SECTIONS)
 * — its default and its words —, and after the setting that names a driver, each driver's own settings as its form
 * describes them (the database's DB_*, the mail's MAIL_*).
 *
 * @return array<string, string>
 */
function configReference(Container $container): array
{
    // The registries whose drivers keep their settings in config.php, by the setting that names the driver.
    $drivers = ['DB_CONNECTION' => 'database.drivers', 'MAIL_TRANSPORT' => 'mail.drivers'];

    $lines = [
        generatedNotice('App\Core\Config\ConfigKeys::SECTIONS and the forms of the drivers registered in bootstrap/container.php'),
        '# config.php reference',
        '',
        'Every setting `config.php` may hold, section by section in the order the file lists them: what the shop runs with',
        'while the file does not set it, and what it is for — the words the file itself says above each setting. A setting',
        'that names a driver (`DB_CONNECTION`, `MAIL_TRANSPORT`) is followed by each driver\'s own settings, as the panel',
        'shows them. [Configuration](Configuration.md) says how the file is made, edited and kept safe.',
    ];

    foreach (ConfigKeys::SECTIONS as $section => $settings) {
        $lines = [...$lines, '', '## ' . $section, '', '| Setting | Default | What it is |', '|---|---|---|'];
        foreach ($settings as $key => [$default, $words]) {
            $lines[] = '| `' . $key . '` | `' . phpValue($default) . '` | ' . cell(str_replace("\n", ' ', $words)) . ' |';
        }
        foreach (array_intersect_key($drivers, $settings) as $key => $registry) {
            $lines = [...$lines, ...driverSettings($key, registry($container, $registry))];
        }
    }

    return ['Config-Reference.md' => implode("\n", $lines) . "\n"];
}

/**
 * The settings of each driver `$setting` may name, under a heading of its own.
 *
 * @param Registry<Driver> $registry
 * @return list<string>
 */
function driverSettings(string $setting, Registry $registry): array
{
    $lines = [];
    foreach ($registry->all() as $driver) {
        $descriptor = $driver->describe();
        $lines = [
            ...$lines,
            '',
            '### The ' . $driver->key() . ' driver',
            '',
            '`\'' . $setting . '\' => \'' . $driver->key() . '\'` — «' . $descriptor->label . '»: «' . $descriptor->description . '»'
                . ($descriptor->notes === [] ? '' : ' (' . implode('؛ ', array_map(static fn(string $note): string => '«' . $note . '»', $descriptor->notes)) . ')'),
        ];
        if ($descriptor->form->fields === []) {
            $lines = [...$lines, '', 'It has no settings of its own.'];

            continue;
        }
        $lines = [...$lines, '', '| Setting | Default | In the panel |', '|---|---|---|'];
        $keys = [];
        foreach ($descriptor->form->fields as $field) {
            $keys[$field->name] = $field->key;
        }
        foreach ($descriptor->form->fields as $field) {
            $lines[] = '| `' . $field->key . '` | `' . phpValue($field->default) . '` | ' . cell(fieldWords($field, $keys)) . ' |';
        }
    }

    return $lines;
}

/**
 * What the panel says of a driver's field: its label and hint, the values it takes, its bounds, when it is shown.
 *
 * @param Field<mixed> $field
 * @param array<string, string> $keys The form's settings by field name
 */
function fieldWords(Field $field, array $keys): string
{
    $described = $field->describe();
    $words = ['«' . $described['label'] . '»' . ($described['hint'] === null ? '' : ' — «' . $described['hint'] . '»')];
    if ($described['options'] !== []) {
        $words[] = 'one of ' . implode(', ', array_map(static fn(array $option): string => '`' . $option['value'] . '` («' . $option['label'] . '»)', $described['options']));
    }
    if ($described['min'] !== null && $described['max'] !== null) {
        $words[] = 'from ' . $described['min'] . ' to ' . $described['max'] . ($described['unit'] === null ? '' : ' («' . $described['unit'] . '»)');
    }
    foreach ((array) $described['when'] as $name => $values) {
        $words[] = 'only while `' . ($keys[$name] ?? $name) . '` is ' . implode(' or ', array_map(static fn(string $value): string => '`' . $value . '`', (array) $values));
    }
    if ($described['required']) {
        $words[] = 'required';
    }
    if ($described['secret']) {
        $words[] = 'a secret: the panel never shows it, and a blank one keeps the one kept';
    }
    if ($described['advanced']) {
        $words[] = 'under «تنظیمات پیشرفته»';
    }

    return implode('; ', $words) . '.';
}

/**
 * docs/Bot-Texts-Reference.md: every text the bot says to a customer, by its group on the «متن‌های ربات» screen
 * (Telegram\Texts\TextCatalog) — its key, title, kind, where the customer meets it and the variables it is filled with.
 *
 * @return array<string, string>
 */
function botTextsReference(): array
{
    $kinds = [
        TextKind::Message->value => 'A message the bot sends.',
        TextKind::Caption->value => 'The caption of the QR card a service is delivered on.',
        TextKind::Popup->value => 'The short notice a tapped button shows.',
        TextKind::Button->value => 'A button\'s label.',
        TextKind::Part->value => 'A piece another text takes in (a status, a note, a hint under a screen); emptied, it leaves nothing.',
    ];

    $lines = [
        generatedNotice('App\Modules\Telegram\Texts\TextCatalog'),
        '# Bot texts reference',
        '',
        'Everything the bot says to a customer is one of these texts. The owner — or an agent, in their own shop — rewords any',
        'of them on «متن‌های ربات» in the panel, which shows each text\'s title and where the customer meets it, and checks',
        'every wording before it is kept: [Bot texts and keyboards](Bot-Texts-And-Keyboards.md). A text is filled with its',
        'variables as it is sent; a required one cannot be left out of a wording. The words in «» are the panel\'s own.',
        '',
        '## Kinds',
        '',
        'What a text is decides what it may hold: Telegram HTML (and premium emoji) in a message, a caption or a part; plain',
        'text in a notice or a button. Its length is counted as Telegram counts it — the text without its tags.',
        '',
        '| Kind | Where it ends up | Telegram HTML | At most |',
        '|---|---|---|---|',
    ];
    foreach (TextKind::cases() as $kind) {
        $lines[] = '| `' . $kind->value . '` | ' . $kinds[$kind->value] . ' | ' . ($kind->html() ? 'yes' : 'no') . ' | ' . $kind->limit() . ' |';
    }

    $lines = [
        ...$lines,
        '',
        '## Variables',
        '',
        'A variable means the same thing in every text that offers it; a text may describe one its own way (said beside it',
        'below). The example is what the panel\'s preview fills it with.',
        '',
        '| Variable | What it holds | Example |',
        '|---|---|---|',
    ];
    foreach (TextCatalog::VARIABLES as $name => [$meaning, $sample]) {
        $lines[] = '| `%' . $name . '%` | «' . cell($meaning) . '» | ' . cell(htmlspecialchars($sample, ENT_NOQUOTES)) . ' |';
    }

    // An agent's shop neither lists nor rewords what only the main bot says (its agency's texts).
    $agentShop = (new Bot())->forceFill(['id' => Bot::MAIN + 1]);
    foreach (TextCatalog::GROUPS as $group => $title) {
        $texts = array_values(array_filter(BotText::cases(), static fn(BotText $text): bool => TextCatalog::spec($text)->group === $group));
        $lines = [...$lines, '', '## Group: ' . $group, '', '«' . $title . '» on the screen.', '', '| Text | Title | Kind | Where the customer meets it | Variables |', '|---|---|---|---|---|'];
        foreach ($texts as $text) {
            $spec = TextCatalog::spec($text);
            $variables = [];
            foreach ($spec->variables as $name => $own) {
                $variables[] = '`%' . $name . '%`' . ($own === null ? '' : ' («' . $own . '»)') . (in_array($name, $spec->required, true) ? ' (required)' : '');
            }
            $mainOnly = !CurrentBot::run($agentShop, static fn(): bool => TextCatalog::says($text));
            $lines[] = '| `' . $text->value . '` | «' . cell($spec->title) . '»' . ($mainOnly ? ' (the main bot\'s only)' : '') . ' | `' . $spec->kind->value . '` | «' . cell($spec->description) . '» | ' . ($variables === [] ? '—' : cell(implode(', ', $variables))) . ' |';
        }
    }

    return ['Bot-Texts-Reference.md' => implode("\n", $lines) . "\n"];
}

/**
 * The API's reference — resources/api/openapi.yaml as the app is held to it, every operation the shop's admins also have
 * on its website given its own path there (Tests\Support\ApiDescription::expanded(), the one place those are made) —, a
 * page an audience: the Store API for the shops' websites, its admin API for their admins, the panels' API for
 * contributors. Each operation with who may call it, its parameters, its body field by field and its answers; the shapes
 * the answers are made of on the page that alone reaches them, the ones several reach once on API-Schemas.md with the
 * answers the description names; and the expanded description itself as docs/openapi.json, for Swagger UI, Redoc or a
 * client generator.
 *
 * @return array<string, string>
 */
function apiReference(): array
{
    $api = ApiDescription::expanded();
    $shared = 'API-Schemas.md';

    $operations = apiOperations($api);
    $inlined = inlinedSchemas($api);
    $reached = [];
    foreach ($operations as $operation) {
        foreach (schemasReached($api, $operation, $inlined) as $name) {
            $reached[$name][$operation['page']] = true;
        }
    }
    // Where each shape is told: on the one page that reaches it, else once on the shared page.
    $homes = array_map(static fn(array $pages): string => count($pages) === 1 ? (string) array_key_first($pages) : $shared, $reached);
    ksort($homes, SORT_STRING);

    $files = [];
    foreach (apiPages($api) as $file => $page) {
        $link = apiLink($homes, $file);
        $lines = [generatedNotice('resources/api/openapi.yaml, as tests/Support/ApiDescription.php expands it'), '# ' . $page['title'], '', ...$page['intro']];
        foreach ($page['sections'] as $section => [$heading, $about]) {
            $here = array_values(array_filter($operations, static fn(array $operation): bool => $operation['page'] === $file && $operation['section'] === $section));
            $lines = [...$lines, '', '## ' . $heading, '', $about, '', '| Operation | What it does |', '|---|---|'];
            foreach ($here as $operation) {
                $lines[] = '| [`' . $operation['heading'] . '`](#' . githubSlug($operation['heading']) . ') | ' . cell(apiText(apiGist($operation['op']['summary'] ?? ''))) . ' |';
            }
            foreach ($here as $operation) {
                $lines = [...$lines, '', ...apiOperation($api, $operation, $link, $inlined, $shared)];
            }
        }
        $lines = [...$lines, ...apiSchemaSections($api, array_keys(array_filter($homes, static fn(string $home): bool => $home === $file)), $link, $inlined)];
        $files[$file] = implode("\n", $lines) . "\n";
    }

    $link = apiLink($homes, $shared);
    $lines = [
        generatedNotice('resources/api/openapi.yaml, as tests/Support/ApiDescription.php expands it'),
        '# API schemas',
        '',
        'The shapes more than one of the API\'s reference pages use — the [Store API](Store-API-Reference.md), its',
        '[admin API](Store-Admin-API-Reference.md) and the [panels\' API](Panels-API-Reference.md) —, each told once, and the',
        'answers the description names once for many operations (a refusal\'s, a picture\'s). A shape one page alone uses is',
        'on that page. Every object is closed: an answer holds the fields listed and no other — each of them, `null` where its',
        'type says so —, and a request sends none but these.',
        '',
        '## Responses',
    ];
    foreach ($api['components']['responses'] as $name => $response) {
        $lines = [...$lines, '', '### Response: ' . $name, '', apiText($response['description'] ?? ''), ''];
        foreach ($response['headers'] ?? [] as $header => $about) {
            $lines[] = '- Header `' . $header . '`' . (($about['required'] ?? false) === true ? ' (always)' : '') . ': ' . apiText($about['description'] ?? '');
        }
        $lines[] = '- Body: ' . apiContent($response, $link) . '.';
    }
    $lines = [...$lines, ...apiSchemaSections($api, array_keys(array_filter($homes, static fn(string $home): bool => $home === $shared)), $link, $inlined)];
    $files[$shared] = implode("\n", $lines) . "\n";

    $files['openapi.json'] = json_encode(apiJson($api), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

    return $files;
}

/**
 * The description as JSON writes it: YAML's empty mapping is an empty PHP array, which JSON would write as a list — an
 * operation's security requirement that asks nothing (`{}`: a bearer token optional), or a mapping of the description's
 * own left empty, is written as the object it is.
 */
function apiJson(mixed $node, string $key = ''): mixed
{
    if (!is_array($node)) {
        return $node;
    }
    if ($node === []) {
        return in_array($key, ['requirement', 'paths', 'components', 'schemas', 'responses', 'properties', 'content', 'headers'], true) ? new \stdClass() : [];
    }
    $json = [];
    foreach ($node as $name => $value) {
        $json[$name] = apiJson($value, $key === 'security' ? 'requirement' : (string) $name);
    }

    return $json;
}

/**
 * The reference's pages for the operations, by their file: each its title, what its intro says — the parameters every
 * operation of it takes told there once, from the description — and its sections in order, each a heading and a line.
 *
 * @param array<string, mixed> $api
 * @return array<string, array{title: string, intro: list<string>, sections: array<string, array{0: string, 1: string}>}>
 */
function apiPages(array $api): array
{
    $store = apiParameterTable($api, ['Store']);
    $grant = $api['components']['schemas']['StaffGrant'];

    return [
        'Store-API-Reference.md' => [
            'title' => 'Store API reference',
            'intro' => [
                'Every operation of a shop\'s website\'s API as its description says it — who may call it, its parameters, its',
                'body field by field and its answers —, then the shapes its answers are made of. How to use them — signing in,',
                'buying, the checkout\'s keys, the errors worth telling apart — is told on the [Store API](Store-API.md)\'s pages;',
                'what the shop\'s admins have on the website is the [admin API reference](Store-Admin-API-Reference.md). The',
                'description is `resources/api/openapi.yaml`, and the same as one JSON file — for Swagger UI, Redoc or a client',
                'generator — is `docs/openapi.json` ([on GitHub](https://github.com/amir-aghajani/amo-bot/blob/main/docs/openapi.json)).',
                '',
                'Paths are under the website\'s base address, `{APP_URL}/api/store/v1/{store}`',
                '([the base address](Store-API.md#the-base-address)):',
                '',
                ...$store,
                '',
                'Every operation may also answer the one error shape ([errors](Store-API.md#errors)). Objects are closed',
                '([API schemas](API-Schemas.md)).',
                '',
                '## The customer scheme',
                '',
                apiText($api['components']['securitySchemes']['customer']['description']),
            ],
            'sections' => [
                'public' => ['Public', 'Anyone may call these: the store key alone.'],
                'customer' => ['Signed-in customer', 'The signed-in customer\'s own, with their bearer token ([the customer scheme](#the-customer-scheme)).'],
            ],
        ],
        'Store-Admin-API-Reference.md' => [
            'title' => 'Admin API reference',
            'intro' => [
                'The shop\'s admins\' API on its website: every operation of the shop\'s daily work the panels have that its admins',
                'have too — the description marks them (`x-staff`), and the tests give each its path here —, as its description',
                'says it. How a website signs its admins in and uses it is [the shop\'s admins](Store-API-Admins.md). Each answers',
                'as in the panels ([the panels\' API](Panels-API-Reference.md)); here it asks an admin\'s bearer token',
                '([the customer scheme](Store-API-Reference.md#the-customer-scheme)) whose sign-in is at most 12 hours old — a',
                'way in proven on the session, its sign-in or `POST /me/reauthenticate` since —, and refuses at the door with a',
                '`403` ([StaffRefused](API-Schemas.md#response-staffrefused)).',
                '',
                'Paths are under the website\'s base address and `/admin`, `{APP_URL}/api/store/v1/{store}/admin`:',
                '',
                ...$store,
                '',
                'Every operation may also answer the one error shape ([errors](Store-API.md#errors)). Objects are closed',
                '([API schemas](API-Schemas.md)).',
                '',
                '## Grants',
                '',
                'The website\'s `staff_grants`, each one of '
                    . implode(', ', array_map(static fn(string $value): string => '`' . $value . '`', $grant['enum'])) . '. '
                    . apiText($grant['description']),
                'An operation that asks one says which — and asks a sign-in of the last 15 minutes too, as approving a payment does',
                'without a grant (`x-staff-recent`).',
            ],
            'sections' => [
                'admin' => ['Operations', 'The shop\'s daily work, each operation as the panels\' own answers it (linked).'],
            ],
        ],
        'Panels-API-Reference.md' => [
            'title' => 'Panels\' API reference',
            'intro' => [
                'The panels\' JSON API, for contributors: every operation the owner\'s panel (`/api/admin/…`) and an agent\'s',
                '(`/api/agent/…`) call, the web installer\'s (`/api/install/…`) and the two anyone may ask, as the description',
                'says it — the description the panels\' TypeScript types are generated from (`pnpm api:types`) and the tests hold',
                'every request and answer to ([Extending](Extending.md#a-panel-screen-and-its-api)).',
                '',
                'A panel\'s request carries its session cookie: every operation asks the panel\'s sign-in but signing in and its',
                'recovery. One that changes something carries `X-Requested-With: XMLHttpRequest` too (the CSRF guard). The',
                'parameters the panels\' operations take everywhere:',
                '',
                ...apiParameterTable($api, ['Panel', 'Shop']),
                '',
                'Every operation may also answer the one error shape. Objects are closed ([API schemas](API-Schemas.md)).',
            ],
            'sections' => [
                'both' => ['Both panels', 'The same screens of the shop in both panels: `{panel}` is `admin` in the owner\'s and `agent` in an agent\'s. One the shop\'s admins also have on its website says so.'],
                'owner' => ['The owner\'s panel', 'What only the owner\'s panel has.'],
                'agent' => ['An agent\'s panel', 'What only an agent\'s panel has.'],
                'installer' => ['The web installer', 'Until the shop is installed, each request with the install key (`X-Install-Key`: the text of storage/install-key.txt, which the first request makes).'],
                'installation' => ['The installation', 'Anyone may ask these.'],
            ],
        ],
    ];
}

/**
 * Every operation of the description, in its order, with the page and the section it is told in, its heading (its method
 * and its path under the page's base) and its parameters as it takes them.
 *
 * @param array<string, mixed> $api
 * @return list<array{page: string, section: string, heading: string, path: string, method: string, op: array<string, mixed>, parameters: list<array<string, mixed>>}>
 */
function apiOperations(array $api): array
{
    // A shop's website's base address in the description, the admins' paths under it at ApiDescription::STAFF.
    $store = '/api/store/v1/{store}';
    $operations = [];
    foreach ($api['paths'] as $path => $item) {
        foreach ($item as $method => $op) {
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            [$page, $section, $base] = match (true) {
                str_starts_with($path, ApiDescription::STAFF . '/') => ['Store-Admin-API-Reference.md', 'admin', ApiDescription::STAFF],
                $path === $store || str_starts_with($path, $store . '/') => ['Store-API-Reference.md', apiSignedIn($op) ? 'customer' : 'public', $store],
                str_starts_with($path, ApiDescription::PANELS . '/') => ['Panels-API-Reference.md', 'both', ''],
                str_starts_with($path, '/api/admin/') => ['Panels-API-Reference.md', 'owner', ''],
                str_starts_with($path, '/api/agent/') => ['Panels-API-Reference.md', 'agent', ''],
                str_starts_with($path, '/api/install') => ['Panels-API-Reference.md', 'installer', ''],
                default => ['Panels-API-Reference.md', 'installation', ''],
            };
            $shown = substr($path, strlen($base));
            $operations[] = [
                'page' => $page,
                'section' => $section,
                'heading' => strtoupper($method) . ' ' . ($shown === '' ? '/' : $shown),
                'path' => $path,
                'method' => $method,
                'op' => $op,
                'parameters' => apiParameters($api, $item['parameters'] ?? [], $op['parameters'] ?? []),
            ];
        }
    }

    return $operations;
}

/**
 * Whether an operation asks a customer's bearer token: its security names one, and no requirement of it asks nothing
 * (`{}` — a token optional: anyone's, the customer's when one comes).
 *
 * @param array<string, mixed> $op
 */
function apiSignedIn(array $op): bool
{
    return isset($op['security']) && !in_array([], $op['security'], true);
}

/**
 * An operation's parameters — its path's, and its own in their place where it names one of theirs again —, a reference
 * resolved to the description's own, which keeps its name (`component`).
 *
 * @param array<string, mixed> $api
 * @param list<array<string, mixed>> $path
 * @param list<array<string, mixed>> $own
 * @return list<array<string, mixed>>
 */
function apiParameters(array $api, array $path, array $own): array
{
    $parameters = [];
    foreach ([...$path, ...$own] as $parameter) {
        if (isset($parameter['$ref'])) {
            $component = basename($parameter['$ref']);
            $parameter = ['component' => $component] + $api['components']['parameters'][$component];
        }
        $parameters[$parameter['in'] . ' ' . $parameter['name']] = $parameter;
    }

    return array_values($parameters);
}

/**
 * The parameters the description names `$components`, as a table: what an intro tells once for every operation of a page.
 *
 * @param array<string, mixed> $api
 * @param list<string> $components
 * @return list<string>
 */
function apiParameterTable(array $api, array $components): array
{
    $rows = ['| Parameter | In | Type | Required | What it is |', '|---|---|---|---|---|'];
    foreach ($components as $component) {
        $rows[] = apiParameterRow($api['components']['parameters'][$component], static fn(string $name): string => '`' . $name . '`');
    }

    return $rows;
}

/**
 * @param array<string, mixed> $parameter
 * @param \Closure(string): string $link
 */
function apiParameterRow(array $parameter, \Closure $link): string
{
    return '| `' . $parameter['name'] . '` | ' . $parameter['in'] . ' | ' . cell(apiType($parameter['schema'] ?? [], $link)) . ' | '
        . (($parameter['required'] ?? false) === true ? 'yes' : 'no') . ' | ' . cell(apiText($parameter['description'] ?? '')) . ' |';
}

/**
 * How an operation is told: its heading, its summary, who may call it (and where else it is), its parameters but the ones
 * its page's intro tells, its body field by field, and its answers.
 *
 * @param array<string, mixed> $api
 * @param array{page: string, section: string, heading: string, path: string, method: string, op: array<string, mixed>, parameters: list<array<string, mixed>>} $operation
 * @param \Closure(string): string $link
 * @param array<string, true> $inlined
 * @return list<string>
 */
function apiOperation(array $api, array $operation, \Closure $link, array $inlined, string $shared): array
{
    $op = $operation['op'];
    $lines = ['### ' . $operation['heading'], '', apiText($op['summary'] ?? '')];

    // Who may call it, and where else it is: an operation of the panels the shop's admins have too, and theirs.
    $facts = [];
    $grant = $op[ApiDescription::MARKER] ?? null;
    $grants = ($operation['page'] === 'Store-Admin-API-Reference.md' ? '' : 'Store-Admin-API-Reference.md') . '#grants';
    $needs = match (true) {
        is_string($grant) => ', when the website grants `' . $grant . '` ([grants](' . $grants . ')) and the sign-in is at most 15 minutes old',
        ($op[ApiDescription::RECENT] ?? false) === true => ', when the sign-in is at most 15 minutes old',
        default => '',
    };
    $who = [
        'public' => isset($op['security']) ? 'anyone — or the signed-in customer, their bearer token with it ([the customer scheme](#the-customer-scheme)).' : 'anyone.',
        'customer' => 'the signed-in customer ([the customer scheme](#the-customer-scheme)).',
        'admin' => 'one of the shop\'s admins, signed in on its website' . $needs . '.',
    ][$operation['section']] ?? null;
    if ($who !== null) {
        $facts[] = '- **Who:** ' . $who;
    }
    if ($operation['section'] === 'admin') {
        $heading = strtoupper($operation['method']) . ' ' . ApiDescription::PANELS . substr($operation['path'], strlen(ApiDescription::STAFF));
        $facts[] = '- **In the panels:** [`' . $heading . '`](Panels-API-Reference.md#' . githubSlug($heading) . ').';
    } elseif ($grant !== null) {
        $heading = strtoupper($operation['method']) . ' ' . substr($operation['path'], strlen(ApiDescription::PANELS));
        $facts[] = '- **On the website:** the shop\'s admins have it too, as [`' . $heading . '`](Store-Admin-API-Reference.md#' . githubSlug($heading) . ')' . $needs . '.';
    }
    if (isset($op['x-captcha'])) {
        $facts[] = '- **Captcha:** the website\'s, for the action `' . $op['x-captcha'] . '` — the widget\'s token in `captcha` ([the captcha](Store-API-Sign-In.md#the-captcha)).';
    }
    if ($facts !== []) {
        $lines = [...$lines, '', ...$facts];
    }

    // The parameters every operation of its page takes are its intro's.
    $common = ['Store-API-Reference.md' => ['Store'], 'Store-Admin-API-Reference.md' => ['Store'], 'Panels-API-Reference.md' => ['Panel', 'Shop']][$operation['page']];
    $parameters = array_filter($operation['parameters'], static fn(array $parameter): bool => !in_array($parameter['component'] ?? null, $common, true));
    if ($parameters !== []) {
        $lines = [...$lines, '', '**Parameters**', '', '| Name | In | Type | Required | What it is |', '|---|---|---|---|---|'];
        foreach ($parameters as $parameter) {
            $lines[] = apiParameterRow($parameter, $link);
        }
    }

    if (($op['x-no-body'] ?? false) === true) {
        $lines = [...$lines, '', '**Body:** none.'];
    }
    foreach ($op['requestBody']['content'] ?? [] as $type => $media) {
        $as = ['application/json' => 'JSON', 'multipart/form-data' => 'a form (`multipart/form-data`)'][$type] ?? '`' . $type . '`';
        $lines = [...$lines, '', '**Body**, as ' . $as . ':', '', ...apiShape($api, $media['schema'], $link, $inlined)];
    }

    $lines = [...$lines, '', '**Answers**', '', '| Status | What it is | Body |', '|---|---|---|'];
    $responses = $op['responses'];
    uksort($responses, static fn(int|string $a, int|string $b): int => [(string) $a === 'default', (string) $a] <=> [(string) $b === 'default', (string) $b]);
    foreach ($responses as $status => $response) {
        if (isset($response['$ref'])) {
            $name = basename($response['$ref']);
            $what = '[' . $name . '](' . $shared . '#' . githubSlug('Response: ' . $name) . ')';
            $response = $api['components']['responses'][$name];
        } else {
            $what = cell(apiText($response['description'] ?? ''));
        }
        $lines[] = '| ' . ((string) $status === 'default' ? 'any other' : '`' . $status . '`') . ' | ' . $what . ' | ' . cell(apiContent($response, $link)) . ' |';
    }

    return $lines;
}

/**
 * The schemas `$names` under the page's «Schemas» heading, in the order of their names: each its description and its shape.
 *
 * @param array<string, mixed> $api
 * @param list<string> $names
 * @param \Closure(string): string $link
 * @param array<string, true> $inlined
 * @return list<string>
 */
function apiSchemaSections(array $api, array $names, \Closure $link, array $inlined): array
{
    if ($names === []) {
        return [];
    }
    $lines = ['', '## Schemas'];
    foreach ($names as $name) {
        $schema = $api['components']['schemas'][$name];
        $lines = [...$lines, '', '### ' . $name, ''];
        if (isset($schema['description'])) {
            $lines = [...$lines, apiText($schema['description']), ''];
        }
        $lines = [...$lines, ...apiShape($api, $schema, $link, $inlined, false)];
    }

    return $lines;
}

/**
 * A shape as these pages tell it: an object's fields as a table (an inner object's under its field's name), the values of
 * an enum, each shape of a choice — a request's body told where it is sent, a shape told elsewhere linked —, else its
 * type in a line.
 *
 * @param array<string, mixed> $api
 * @param array<string, mixed> $schema
 * @param \Closure(string): string $link
 * @param array<string, true> $inlined
 * @return list<string>
 */
function apiShape(array $api, array $schema, \Closure $link, array $inlined, bool $describe = true): array
{
    if (isset($schema['$ref'])) {
        $name = basename($schema['$ref']);
        if (!isset($inlined[$name])) {
            return [ucfirst(apiType($schema, $link)) . '.'];
        }
        $schema = $api['components']['schemas'][$name];
        $lines = $describe && isset($schema['description']) ? [apiText($schema['description']), ''] : [];

        return [...$lines, ...apiShape($api, $schema, $link, $inlined, false)];
    }
    if (isset($schema['oneOf'])) {
        $lines = ['One of:'];
        foreach ($schema['oneOf'] as $choice) {
            $name = isset($choice['$ref']) ? basename($choice['$ref']) : null;
            if ($name !== null && isset($inlined[$name])) {
                $lines = [...$lines, '', '**`' . $name . '`**' . (isset($api['components']['schemas'][$name]['description']) ? ' — ' . apiText($api['components']['schemas'][$name]['description']) : ''), '', ...apiShape($api, $api['components']['schemas'][$name], $link, $inlined, false)];
            } else {
                $lines[] = '- ' . apiType($choice, $link);
            }
        }

        return $lines;
    }
    if (isset($schema['properties'])) {
        return ['| Field | Type | Required | What it is |', '|---|---|---|---|', ...apiFieldRows($schema, '', $link)];
    }

    return [ucfirst(apiType($schema, $link)) . '.'];
}

/**
 * An object's fields as rows of its table — an inner object's, or a list of them, under the field's name (`name.field`,
 * `name[].field`).
 *
 * @param array<string, mixed> $schema
 * @param \Closure(string): string $link
 * @return list<string>
 */
function apiFieldRows(array $schema, string $prefix, \Closure $link): array
{
    $rows = [];
    $required = $schema['required'] ?? [];
    foreach ($schema['properties'] ?? [] as $name => $property) {
        $rows[] = '| `' . $prefix . $name . '` | ' . cell(apiType($property, $link)) . ' | ' . (in_array($name, $required, true) ? 'yes' : 'no') . ' | ' . cell(apiText($property['description'] ?? '')) . ' |';
        if (isset($property['properties'])) {
            $rows = [...$rows, ...apiFieldRows($property, $prefix . $name . '.', $link)];
        } elseif (isset($property['items']['properties'])) {
            $rows = [...$rows, ...apiFieldRows($property['items'], $prefix . $name . '[].', $link)];
        }
    }

    return $rows;
}

/**
 * A schema's type in a few words — a shape told elsewhere as a link to it, a list of what, an enum's values, a string's
 * format and pattern, a number's least —, `or null` where it may be.
 *
 * @param array<string, mixed> $schema
 * @param \Closure(string): string $link
 */
function apiType(array $schema, \Closure $link): string
{
    $words = match (true) {
        isset($schema['$ref']) => $link(basename($schema['$ref'])),
        isset($schema['allOf']) => implode(' and ', array_map(static fn(array $part): string => apiType($part, $link), $schema['allOf'])),
        isset($schema['oneOf']) => implode(' or ', array_map(static fn(array $choice): string => apiType($choice, $link), $schema['oneOf'])),
        ($schema['type'] ?? null) === 'array' => 'list of ' . apiType($schema['items'] ?? [], $link),
        ($schema['type'] ?? null) === 'object' && !isset($schema['properties']) && is_array($schema['additionalProperties'] ?? null) => 'map of ' . apiType($schema['additionalProperties'], $link),
        ($schema['format'] ?? null) === 'binary' => 'file',
        default => (string) ($schema['type'] ?? 'any value'),
    };
    if (isset($schema['format']) && $schema['format'] !== 'binary') {
        $words .= ' (' . $schema['format'] . ')';
    }
    if (isset($schema['enum'])) {
        $words .= ': ' . implode(', ', array_map(static fn(mixed $value): string => '`' . (is_string($value) ? $value : json_encode($value)) . '`', $schema['enum']));
    }
    if (isset($schema['pattern'])) {
        $words .= ' matching `' . $schema['pattern'] . '`';
    }
    if (isset($schema['minimum'])) {
        $words .= ', at least ' . $schema['minimum'];
    }
    if (isset($schema['maxItems'])) {
        $words .= ', ' . $schema['maxItems'] . ' at most';
    }

    return $words . (($schema['nullable'] ?? false) === true ? ' or null' : '');
}

/**
 * What an answer carries: a shape (JSON), the bytes of a file of its media types, or nothing.
 *
 * @param array<string, mixed> $response
 * @param \Closure(string): string $link
 */
function apiContent(array $response, \Closure $link): string
{
    $json = [];
    $bytes = [];
    foreach ($response['content'] ?? [] as $type => $media) {
        if ($type === 'application/json') {
            $json[] = apiType($media['schema'], $link);
        } else {
            $bytes[] = '`' . $type . '`';
        }
    }

    return implode('; ', [...$json, ...($bytes === [] ? [] : ['the bytes, as ' . implode(', ', $bytes)])]) ?: 'none';
}

/**
 * How a page names a shape: a link to where it is told — on this page, on another, or on the shared page.
 *
 * @param array<string, string> $homes Each shape's page
 * @return \Closure(string): string
 */
function apiLink(array $homes, string $page): \Closure
{
    return static function (string $name) use ($homes, $page): string {
        $home = $homes[$name] ?? null;
        if ($home === null) {
            return '`' . $name . '`';
        }

        return '[' . $name . '](' . ($home === $page ? '' : $home) . '#' . githubSlug($name) . ')';
    };
}

/**
 * The shapes a request's body alone uses — told at the operation that takes it, field by field, never under «Schemas»:
 * one no other shape, answer or parameter names, or a choice of such a body.
 *
 * @param array<string, mixed> $api
 * @return array<string, true>
 */
function inlinedSchemas(array $api): array
{
    $bodies = [];
    $named = [...schemaRefs($api['components']['responses']), ...schemaRefs($api['components']['parameters'])];
    foreach ($api['paths'] as $item) {
        foreach ($item as $op) {
            if (!is_array($op)) {
                continue;
            }
            foreach ($op['requestBody']['content'] ?? [] as $media) {
                if (isset($media['schema']['$ref'])) {
                    $bodies[basename($media['schema']['$ref'])] = true;
                } else {
                    $named = [...$named, ...schemaRefs($media['schema'] ?? [])];
                }
            }
            unset($op['requestBody']);
            $named = [...$named, ...schemaRefs($op)];
        }
    }
    $choices = [];
    foreach ($api['components']['schemas'] as $name => $schema) {
        foreach ($schema['oneOf'] ?? [] as $choice) {
            if (isset($choice['$ref'])) {
                $choices[basename($choice['$ref'])][] = $name;
            }
        }
        unset($schema['oneOf']);
        $named = [...$named, ...schemaRefs($schema)];
    }
    $named = array_fill_keys($named, true);

    // A body, or a choice of one, that nothing else names.
    $inlined = array_diff_key($bodies + array_fill_keys(array_keys($choices), true), $named);
    do {
        $before = count($inlined);
        foreach (array_keys($inlined) as $name) {
            if (!isset($bodies[$name]) && array_diff($choices[$name] ?? [], array_keys($inlined)) !== []) {
                unset($inlined[$name]);
            }
        }
    } while (count($inlined) !== $before);

    return $inlined;
}

/**
 * The shapes an operation reaches — its parameters' (its path's too), its body's, its answers' (the description's own
 * answers too), and every shape they name in turn —, but the bodies told where they are sent.
 *
 * @param array<string, mixed> $api
 * @param array{op: array<string, mixed>, parameters: list<array<string, mixed>>} $operation
 * @param array<string, true> $inlined
 * @return list<string>
 */
function schemasReached(array $api, array $operation, array $inlined): array
{
    $responses = [];
    foreach ($operation['op']['responses'] ?? [] as $response) {
        $responses[] = isset($response['$ref']) ? $api['components']['responses'][basename($response['$ref'])] : $response;
    }
    $seen = [];
    $queue = schemaRefs([$operation['parameters'], $operation['op']['requestBody'] ?? [], $responses]);
    while ($queue !== []) {
        $name = array_shift($queue);
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $queue = [...$queue, ...schemaRefs($api['components']['schemas'][$name] ?? fail("resources/api/openapi.yaml names a shape it does not describe: {$name}."))];
    }

    return array_values(array_filter(array_keys($seen), static fn(string $name): bool => !isset($inlined[$name])));
}

/**
 * The names of the shapes a part of the description names (`#/components/schemas/…`), however deep.
 *
 * @return list<string>
 */
function schemaRefs(mixed $node): array
{
    if (!is_array($node)) {
        return [];
    }
    $names = [];
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
            $names[] = basename($value);
        } else {
            $names = [...$names, ...schemaRefs($value)];
        }
    }

    return $names;
}

/** An operation's summary in a few words: up to where it goes on — a colon, a dash, a semicolon, the end of a sentence. */
function apiGist(string $summary): string
{
    $summary = (string) preg_replace('/\s+/', ' ', trim($summary));
    $cut = preg_split('/(?::\s|\s—\s|;\s|\.\s)/u', $summary, 2);

    return rtrim($cut[0] ?? $summary, '.');
}

/**
 * The description's words as Markdown reads them: on one line, and a `<` or `>` outside a code span written as an entity
 * (else a page would take `<token>` for a tag and drop it).
 */
function apiText(string $text): string
{
    $parts = preg_split('/(`[^`]*`)/', (string) preg_replace('/\s+/u', ' ', trim($text)), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            $parts[$index] = str_replace(['<', '>'], ['&lt;', '&gt;'], $part);
        }
    }

    return implode('', $parts);
}

/** The comment a generated page starts with: what it is made from, and how to make it again. */
function generatedNotice(string $source): string
{
    return "<!-- Generated by scripts/docs.php from {$source}. Do not edit it: change the code, then run `php scripts/docs.php`. -->\n";
}

/**
 * The registry of drivers the container keeps under `$id`.
 *
 * @return Registry<Driver>
 */
function registry(Container $container, string $id): Registry
{
    $registry = $container->get($id);
    if (!$registry instanceof Registry) {
        fail("The container's {$id} is no driver registry.");
    }

    return $registry;
}

/** A value as config.php writes it. */
function phpValue(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        default => var_export($value, true),
    };
}

/** Text that goes in a table's cell: on one line, its bars escaped. */
function cell(string $text): string
{
    return str_replace(['|', "\r\n", "\n"], ['\|', ' ↵ ', ' ↵ '], $text);
}

/** @return list<string> The generated pages that are not as the code makes them now */
function stalePages(string $root, string $docs): array
{
    $problems = [];
    foreach (generatedPages($root) as $name => $markdown) {
        if (readPage($docs . '/' . $name) !== $markdown) {
            $problems[] = "docs/{$name} is not as the code makes it now: run `php scripts/docs.php`.";
        }
    }

    return $problems;
}

// --- the links -------------------------------------------------------------------------------------------------------

/**
 * What is wrong with the tree's pages and links: a page whose name is not of the tree's form, or that the sidebar does
 * not list; a heading GitHub and Docsify would give two anchors; a link between pages to a page or a heading that is
 * not there, or one that leaves the tree (the wiki and Pages have nothing but docs/); and the links of the repository's
 * own pages (README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md) to its files.
 *
 * @return list<string>
 */
function linkProblems(string $root, string $docs): array
{
    $problems = [];
    $pages = pages($docs);
    $anchors = [];
    $folded = [];
    foreach ($pages as $name => $markdown) {
        if (preg_match('/^(_Sidebar|_Footer|[A-Z][A-Za-z0-9]*(-[A-Za-z0-9]+)*)\.md$/', $name) !== 1) {
            $problems[] = "docs/{$name}: a page's name is Title-Case-With-Dashes.md (the wiki has one namespace, and Docsify a route a page).";
        }
        if (isset($folded[strtolower($name)])) {
            $problems[] = "docs/{$name}: another page has this name in another case ({$folded[strtolower($name)]}).";
        }
        $folded[strtolower($name)] = $name;
        $anchors[$name] = [];
        foreach (headings($markdown) as [$line, $text]) {
            $github = githubSlug($text);
            $docsify = docsifySlug($text);
            if ($github !== $docsify) {
                $problems[] = "docs/{$name}:{$line}: the heading \"{$text}\" is #{$github} on GitHub but #{$docsify} in Docsify: leave out what one drops and the other keeps (a dash or a slash between spaces, a leading digit, «»).";
            }
            $anchors[$name][] = $github;
        }
        $anchors[$name] = numbered($anchors[$name]);
    }

    $sidebar = [];
    foreach ($pages as $name => $markdown) {
        foreach (links($markdown) as [$line, $target]) {
            if (isExternal($target)) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9-]+\.md)?(?:#(.*))?$/', $target, $match) !== 1 || $target === '') {
                $problems[] = "docs/{$name}:{$line}: ({$target}) leaves the tree — a page links to a page of docs/ as Page-Name.md, and to anything else by its full https:// address.";

                continue;
            }
            $page = ($match[1] ?? '') === '' ? $name : $match[1];
            if ($name === '_Sidebar.md') {
                $sidebar[$page] = true;
            }
            $problem = targetProblem($anchors, $page, $match[2] ?? null);
            if ($problem !== null) {
                $problems[] = "docs/{$name}:{$line}: ({$target}) {$problem}";
            }
        }
    }
    foreach (array_keys($pages) as $name) {
        if (!str_starts_with($name, '_') && !isset($sidebar[$name])) {
            $problems[] = "docs/{$name}: _Sidebar.md does not list it — every page is in the navigation.";
        }
    }

    // The repository's own pages link to its files, to the tree's pages (docs/Page-Name.md#anchor) and to themselves.
    foreach (['README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'CHANGELOG.md'] as $file) {
        $markdown = readPage($root . '/' . $file);
        $anchors[$file] = numbered(array_map(static fn(array $heading): string => githubSlug($heading[1]), headings($markdown)));
        foreach (links($markdown) as [$line, $target]) {
            if (isExternal($target)) {
                continue;
            }
            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $page = $path === '' ? $file : (preg_match('#^docs/([^/]+\.md)$#', $path, $match) === 1 ? $match[1] : null);
            if ($page !== null) {
                $problem = targetProblem($anchors, $page, $anchor);
                if ($problem !== null) {
                    $problems[] = "{$file}:{$line}: ({$target}) {$problem}";
                }
            } elseif (!file_exists($root . '/' . $path)) {
                $problems[] = "{$file}:{$line}: ({$target}) is no file of the repository.";
            }
        }
    }

    return $problems;
}

/**
 * Why a link to `$page` (and its heading `$anchor`) leads nowhere; null when it leads somewhere.
 *
 * @param array<string, list<string>> $anchors Each page's anchors
 */
function targetProblem(array $anchors, string $page, ?string $anchor): ?string
{
    if (!isset($anchors[$page])) {
        return 'is no page of docs/.';
    }
    if ($anchor !== null && !in_array($anchor, $anchors[$page], true)) {
        return "names no heading of {$page} (its anchors: " . implode(', ', $anchors[$page]) . ').';
    }

    return null;
}

function isExternal(string $target): bool
{
    return preg_match('#^(https?:|mailto:)#', $target) === 1;
}

/**
 * Every page of the tree, by name.
 *
 * @return array<string, string>
 */
function pages(string $docs): array
{
    $pages = [];
    foreach (glob($docs . '/*.md') ?: [] as $file) {
        $pages[basename($file)] = readPage($file);
    }
    ksort($pages);

    return $pages;
}

/** A page as Markdown, its line breaks LF whatever the checkout did to them; nothing for a page that is not there. */
function readPage(string $file): string
{
    return is_file($file) ? str_replace("\r\n", "\n", (string) file_get_contents($file)) : '';
}

/**
 * The headings of a page (outside its code), each with its line and its text as the reader sees it.
 *
 * @return list<array{0: int, 1: string}>
 */
function headings(string $markdown): array
{
    $headings = [];
    foreach (proseLines($markdown) as $number => $line) {
        if (preg_match('/^ {0,3}#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*$/', $line, $match) === 1) {
            // What the reader sees of the heading: a link's words, code without its backticks, no emphasis marks.
            $headings[] = [$number, str_replace(['`', '*'], '', (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $match[1]))];
        }
    }

    return $headings;
}

/**
 * The anchors of a page's headings in order, a second of one name numbered as GitHub and Docsify both number it (-1, -2).
 *
 * @param list<string> $slugs
 * @return list<string>
 */
function numbered(array $slugs): array
{
    $seen = [];
    $anchors = [];
    foreach ($slugs as $slug) {
        $anchors[] = isset($seen[$slug]) ? $slug . '-' . $seen[$slug] : $slug;
        $seen[$slug] = ($seen[$slug] ?? 0) + 1;
    }

    return $anchors;
}

/** A heading's anchor as GitHub makes it (github-slugger): lower case, every mark but letters, digits, _ and - dropped, each space a dash. */
function githubSlug(string $text): string
{
    return str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', mb_strtolower($text)));
}

/** A heading's anchor as Docsify makes it (its slugify): Latin letters lowered, its marks dropped, spaces and dash runs one dash. */
function docsifySlug(string $text): string
{
    $slug = (string) preg_replace_callback('/[A-Z]+/', static fn(array $match): string => strtolower($match[0]), trim($text));
    $slug = (string) preg_replace('/[\x{2000}-\x{206F}\x{2E00}-\x{2E7F}\\\\\'!"#$%&()*+,.\/:;<=>?@\[\]^`{|}~]/u', '', $slug);
    $slug = (string) preg_replace('/-+/', '-', (string) preg_replace('/\s/u', '-', $slug));

    return (string) preg_replace('/^(\d)/', '_$1', $slug);
}

/**
 * Every link of a page — `[words](target)`, an image's too — outside its code, with its line.
 *
 * @return list<array{0: int, 1: string}>
 */
function links(string $markdown): array
{
    $links = [];
    mapLinks($markdown, static function (string $target, int $line) use (&$links): string {
        $links[] = [$line, $target];

        return $target;
    });

    return $links;
}

/**
 * The page with every link outside its code — a fence, a code span — given the target `$link` answers for it.
 *
 * @param \Closure(string, int): string $link The target, and its line
 */
function mapLinks(string $markdown, \Closure $link): string
{
    $lines = explode("\n", $markdown);
    foreach (proseLines($markdown) as $number => $line) {
        $lines[$number - 1] = (string) preg_replace_callback(
            '/(`+)(?:(?!\1).)+?\1|\]\(([^)\s]+)((?:\s+"[^"]*")?)\)/',
            static fn(array $match): string => ($match[2] ?? '') === '' ? $match[0] : '](' . $link($match[2], $number) . ($match[3] ?? '') . ')',
            $line,
        );
    }

    return implode("\n", $lines);
}

/**
 * The lines of a page that are prose — not a fenced block of code —, by their number.
 *
 * @return array<int, string>
 */
function proseLines(string $markdown): array
{
    $prose = [];
    $fence = null;
    foreach (explode("\n", $markdown) as $index => $line) {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $match) === 1) {
            if ($fence === null) {
                $fence = $match[1];
            } elseif ($match[1][0] === $fence[0] && strlen($match[1]) >= strlen($fence)) {
                $fence = null;
            }

            continue;
        }
        if ($fence === null) {
            $prose[$index + 1] = $line;
        }
    }

    return $prose;
}

// --- the wiki --------------------------------------------------------------------------------------------------------

/**
 * The tree as GitHub's wiki has it, into `$dir` — a checkout of the wiki's repository: every page, its links to pages
 * written without `.md` (Page-Name, Page-Name#anchor); and a page the tree no longer has taken out of it, so the wiki is
 * the tree and nothing else.
 */
function exportWiki(string $root, string $docs, string $dir): void
{
    $target = realpath($dir);
    if ($target === false || !is_dir($target . '/.git')) {
        fail("{$dir} is no checkout of a wiki: clone https://github.com/<owner>/<repo>.wiki.git there first.");
    }
    if ($target === realpath($root) || $target === realpath($docs)) {
        fail("{$dir} is this repository: the wiki's pages go to a checkout of the wiki's own repository.");
    }

    $pages = pages($docs);
    foreach (glob($target . '/*.md') ?: [] as $file) {
        if (!isset($pages[basename($file)])) {
            unlink($file);
            fwrite(STDOUT, 'Removed ' . basename($file) . "\n");
        }
    }
    foreach ($pages as $name => $markdown) {
        $wiki = mapLinks($markdown, static fn(string $target): string => (string) preg_replace('/^([A-Za-z0-9-]+)\.md(?=#|$)/', '$1', $target));
        file_put_contents($target . '/' . $name, $wiki);
    }
    fwrite(STDOUT, sprintf("%d pages written to %s\n", count($pages), $target));
}

function fail(string $message): never
{
    fwrite(STDERR, "docs: {$message}\n");
    exit(1);
}
