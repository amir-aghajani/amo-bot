[فارسی](fa/API-Contract.md)

# API contract

Every endpoint PHP answers is described once, in OpenAPI 3.0.3: `resources/api/openapi.yaml`. Both sides are held to
it — the PHP tests validate every request they send and every answer they get, the panels' TypeScript types are
generated from it, and the panels' tests check every request they make against it. A drift between the server and a
client fails a test before it reaches a shop. The generated references ([panels](Panels-API-Reference.md),
[Store API](Store-API-Reference.md), [website admins](Store-Admin-API-Reference.md), [schemas](API-Schemas.md)) are
written from the same file.

## What the description holds

- **Every operation** of `routes/api.php`, with the schema of every success answer. Errors share one shape,
  `ErrorResponse` (`{message, errors?, request_id}`), under `default`.
- **Closed objects** (`additionalProperties: false`): a field the server sends, or a request carries, that the
  description lacks is a failure, never a silent extra. An object without fields is a map of what its
  `additionalProperties` says, or empty. In an answer every field is `required` and `nullable` where it may be null.
- **Every request body.** A POST, PUT or PATCH has a named `requestBody` schema (so the generator makes a type, e.g.
  `PlanRequest`) or says it takes none with `x-no-body: true`. A request field is `required` when the server refuses
  the request without it; numbers may come as JSON numbers or text (`WholeNumberInput`, `AmountInput`), switches as
  booleans. A family of drivers is saved by a `oneOf` with one closed shape per driver ([Drivers](Drivers.md)), so a
  request is checked against exactly its driver's fields; what a driver's form keeps is answered as `DriverValues`.
- **Paths shared by both panels** are described once, under `/api/{panel}/…` with the `Panel` path parameter (admin,
  agent); what only one panel has is under `/api/admin/…` or `/api/agent/…`. Every operation of the owner's panel takes
  the `X-Shop` header (the shop its tab shows), and a file read takes the `shop` query parameter instead — an `<img>`
  sends no header.
- **The `Idempotency-Key` header** of a write that may be sent again is a parameter of its own for each kind of write
  (`IdempotencyKey` for a checkout, `TransferKey`, `ManualOrderKey`, `WalletAdjustmentKey`, `TrafficAdjustmentKey`,
  `TicketAnswerKey`): required where the server refuses the request without one, and its description says what a
  repeat is answered with and which change of the request is refused.
- **Extension markers**:
  - `x-staff` on an operation of the shop's daily work that the website's admins have too — `true`, or the
    `StaffGrant` the shop must give for it — and `x-staff-recent: true` where it asks a recent sign-in without a grant
    (approving a payment). These paths are never written out: `tests/Support/ApiDescription` expands each marked
    operation under `/api/store/v1/{store}/admin/…` (the store key in place of the panel, the customer bearer scheme,
    its 401 and 403). See [the shop's admins](Store-API-Admins.md).
  - `x-captcha` on a body with a `captcha` field: the action the token must be solved for.
- **The Store API's promise**: v1 only gains fields — none is removed, renamed or retyped, and a request takes what it
  took. A breaking change comes as v2, beside v1.

## How the PHP side is held to it

- **Every HTTP test** (`Tests\HttpTestCase::handle()`, with `league/openapi-psr7-validator`) validates the request
  before it goes in — path, query, headers, body, and no body where the operation takes none — and the answer as it
  comes back: status, content type, every field. An address that is nobody's route may only answer 404 or 405. A test
  whose point is a request no client sends (a wrong type, a missing field) sends it through `unchecked()`; its answer is
  still validated, and an unchecked request that turns out to match fails the test.
- **`tests/Unit/Http/ApiDescriptionTest`** checks:
  - the file is valid OpenAPI, and a website's own tools read it as it is (`league/openapi-psr7-validator` straight
    from the YAML, with the YAML parser's default limits — see the pitfalls below);
  - it names exactly the app's routes (a `{panel}` path once per panel, each shared path declaring its panels);
  - every change describes its body or says it takes none;
  - every object is closed, or a map (`testEveryObjectIsClosed`);
  - every owner's path takes `X-Shop`, and the store's paths declare their key and token;
  - a body with a `captcha` says its action;
  - `routes/api.php` mounts for the websites' admins exactly the marked operations, with their grants.
- **`tests/Feature/RouteGuardsTest`** walks every route with a field no operation takes, every guard in order, and
  another shop's row (a 404).
- **`tests/Unit/Drivers/DriverFormsTest`** holds each driver family's request shapes to the registered drivers' forms,
  both ways.

## Generated types

`scripts/api-types.mjs` (`pnpm api:types`) writes `resources/panel/src/lib/api-types.ts`: one exported type per
schema, and each API's writes as one union (`PanelWrite`, `InstallWrite`, `StoreWrite`) — a member per operation with
its method, its path (a parameter as the type of its value), its body and its answer. Never edit the file by hand;
`pnpm check` fails while it is stale. The generator is the project's own because the usual one needs the TypeScript 5
compiler API.

`lib/api`'s `apiClient<W>` types every `post`/`put`/`patch`/`delete` by its path: a body with a field the operation does
not read, one missing a required field, a body to a write that takes none, or an address no write has, is a compile
error — and the answer is the operation's own type. A read names its answer (`api.get<PlansResponse>('/plans')`).

## How the panels' tests are held to it

Every panel test runs against a fake server (`resources/panel/src/test/server`) in place of `fetch`, and
`src/test/contract` checks each request it receives against the description: the operation of its method and path,
its path, query and header parameters, and its body by the schema, with nothing where it takes none. A request no
route of the test answers fails, and so does one the description does not take; `server().unchecked(method, path)`
lets one malformed request through, and fails when the description would take it after all. The checker implements the
subset of OpenAPI the file uses and fails loudly on a keyword it does not know.

## Changing the API

- **A new endpoint**: the route in `routes/api.php`, the operation in the description (its body or `x-no-body`, its
  answer, `x-staff` if the website's admins should have it), then `pnpm api:types`, then the panel code TypeScript
  points at.
- **A changed answer**: the presenter, the schema, `pnpm api:types`.
- **A changed request**: the service that reads it, its request schema, `pnpm api:types`, the screen that sends it.
- After any edit of the description, `php scripts/docs.php` regenerates the reference pages and `docs/openapi.json`.

YAML pitfalls:

- In a one-line `{ … }` mapping, a description containing a comma must be quoted.
- A `$ref` takes no sibling: wrap it (`{ allOf: [{ $ref: … }], description: … }`).
- Shared property sets use anchors with `<<:` merge keys, the anchor above its aliases.
- A single field repeated elsewhere shares its **wording** by an anchor on its description's text
  (`description: &paymentGateway '…'`, then `description: *paymentGateway`), never by an anchor on the whole field. The
  YAML parser's guard against an alias bomb allows at most 128 aliases of a shared mapping or list, and a website's own
  tools read the file with that guard as it comes: past it, they refuse the file. An alias of a plain text is not
  counted. `tests/Support/ApiDescription::read()` reads the file the same way, so the tests fail first.

## Where to start

- `resources/api/openapi.yaml`
- `tests/HttpTestCase.php` (`handle()`, `checkRequest()`), `tests/Support/ApiDescription.php`
- `tests/Unit/Http/ApiDescriptionTest.php`, `tests/Feature/RouteGuardsTest.php`
- `scripts/api-types.mjs`, `resources/panel/src/lib/api.ts`, `resources/panel/src/test/contract.ts`
