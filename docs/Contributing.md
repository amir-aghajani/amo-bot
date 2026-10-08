# Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/amir-aghajani/amo-bot). A weakness that could hurt a
shop is reported privately instead: [Security](Security.md).

## Setting up and the checks

[Development](Development.md) says what you need and how to run AmoBot locally. Before a pull request, run the checks
([the checks](Development.md#the-checks)) — CI runs them on every push to `main` and every pull request to it
([Testing](Testing.md#ci)):

- `composer check` — the code style, PHPStan (level 6, and dead code) and the PHP tests;
- `pnpm check` when the panels changed — their API types current, the type check, oxlint, knip, Prettier and their tests;
- `php scripts/docs.php --check` — the documentation's generated pages current, and every link leading somewhere.

## The rules a change keeps

The engineers' handbook, [`CLAUDE.md`](https://github.com/amir-aghajani/amo-bot/blob/main/CLAUDE.md) at the repository's
root, has every subsystem's rules and the decisions behind them: read the part your change touches. In brief:

- **Every change of behaviour comes with its tests**, in the suites' own way ([Testing](Testing.md)): strict, in random
  order, off the network, their rows from `Tests\Support\Fixtures`.
- **The API is described once.** A change to a request or an answer changes `resources/api/openapi.yaml` too, then
  `pnpm api:types`: every HTTP test, and every request a panel's test sends, is checked against it.
- **Nothing without a caller.** Dead code fails the analysis (PHPStan's dead-code check, knip), and a test is no caller.
- **One rule, one place.** A business rule lives in the domain service the bot, the panels and the website all call;
  look for the helper that exists before writing another.
- **A module leans on another by decision.** Which modules each module names is one map, `MODULES` in
  `tests/Unit/Core/LayersTest.php`: a new dependency fails the test until the map says it, with a word on why when it is
  more than a plain use of the other module's rows or services ([Architecture](Architecture.md#modules)).
- **Where a new piece goes**: [Extending](Extending.md); a new VPN panel, payment gateway, database, mail service or
  captcha: [Drivers](Drivers.md).

## Proposing a change

1. Open an issue first for anything larger than a fix — a feature, a new driver, a change to an API's contract — so its
   shape is agreed before the work. For a bug, say the version (`Application::VERSION`, or the commit), the host, where
   it happens and how to make it happen: the issue forms ask for each.
2. Branch from `main`, keep the change to one subject, and run the checks. A change a shop's owner, its customers or a
   website's developer would notice gets a line in the
   [changelog](https://github.com/amir-aghajani/amo-bot/blob/main/CHANGELOG.md), under `## Unreleased` at its top —
   the section a release renames to its version, and publishes as its notes.
3. Open a pull request that says what changes for the shop's owner, its customers or a website's developer, why, and how
   it is tested — its template lists the checks. CI must pass.

What you contribute is published under the project's MIT license.

## Language

Everything a user sees — the bots, the panels, the installer, the API's messages — is Persian, by design; code,
comments, console output and these pages are English.

- Persian is written plainly, without Arabic diacritics: no tanwin or harakat (`لطفا`, not `لطفاً`), no hamza-ezafe
  (`نسخه ۳`, not `نسخهٔ ۳`), and `تایید` rather than `تأیید`; the zero-width non-joiner in compounds (`می‌شود`) stays.
- Technical terms keep their Latin form or the transliteration everyone uses (`Webhook`, `Session`, `دیتابیس`, `توکن`),
  never a formal calque. A customer reads «پشتیبانی», never «مدیر».
- What the bot says to a customer is a bot text the owner may reword ([Extending](Extending.md#a-bot-command-or-screen)).
- In these pages, Persian appears only where it is the product's own words — a button, a message —, quoted in «» as the
  panels show them, or in code as the API sends them.

## Documentation

These pages are one tree, `docs/`, and three places read it as it is: the repository's own view on GitHub, the
project's GitHub wiki, and GitHub Pages.

- **The pages** are flat — the wiki has one namespace — English, GitHub-flavoured Markdown, one subject a page, named
  `Title-Case-With-Dashes.md`. `Home.md` is the front page, `_Sidebar.md` the navigation (the wiki and Docsify both read
  it; every page is listed there), `_Footer.md` the footer.
- **Links between pages** are written `[words](Page-Name.md)` or `[words](Page-Name.md#anchor)` — the repository's view
  and Docsify follow them as they are, and the wiki's export rewrites them. Anything outside the tree — a file of the
  repository, another site — is linked by its full `https://` address: the wiki and Pages have nothing but these pages.
  A heading that is linked to keeps to letters, digits, spaces and plain punctuation, so GitHub and Docsify give it one
  anchor (no dash or slash between spaces, no leading digit, no «»).
- **Generated pages** — the [config.php reference](Config-Reference.md), the
  [bot texts reference](Bot-Texts-Reference.md) and the API's reference (the [Store API](Store-API-Reference.md), its
  [admin API](Store-Admin-API-Reference.md), the [panels' API](Panels-API-Reference.md), the [schemas](API-Schemas.md)
  they share), with `docs/openapi.json` — are written from the code by `php scripts/docs.php` and say so at their top;
  never edit them by hand. After a change to `Core\Config\ConfigKeys`, a database or mail driver's form,
  `Telegram\Texts\TextCatalog` or `resources/api/openapi.yaml`, run the script and commit what it wrote. The API's pages
  are written from the description as the tests read it — `tests/Support/ApiDescription.php`, which gives each operation
  the shop's admins have its path on the website —: a shape one page alone uses is told there, one several use once on
  the shared page, and a request's body field by field at its operation.
- **The check** — `php scripts/docs.php --check`, which CI runs — fails while a generated page is stale, a link leads to
  no page or no heading, a page is not in the sidebar or its name is not of the tree's form, or a heading would get two
  anchors; it checks the links of the repository's own `README.md`, `CONTRIBUTING.md`, `SECURITY.md` and `CHANGELOG.md`
  too.
- **The wiki** is published by `.github/workflows/wiki.yml` on every push to the default branch that changes `docs/`:
  it checks out the wiki's repository, runs `php scripts/docs.php --wiki <dir>` — every page, its links written without
  `.md`, and a page the tree no longer has taken out — and pushes. GitHub makes a wiki's repository only once its first
  page exists, so switch the wiki on (Settings › Features › Wikis) and create one page by hand (the Wiki tab's *Create
  the first page*); the export then replaces it. Until that page exists, a run says so in a warning and publishes
  nothing — run the workflow again by hand (Actions › Wiki › Run workflow) once the page is there.
- **GitHub Pages** serves `docs/` as it is: Settings › Pages › Deploy from a branch, the default branch, `/docs`.
  `docs/index.html` is [Docsify](https://docsify.js.org), which renders the Markdown in the browser — its script, its
  theme and its search pinned by version from jsDelivr, with `_Sidebar.md` as its sidebar and `Home.md` as its front
  page —, and `docs/.nojekyll` keeps GitHub's Jekyll from hiding the `_`-named files. There is nothing to build or
  install; any static file server previews it locally (`npx docsify-cli serve docs`).
- **Any other Markdown documentation system** reads the same files: MkDocs (`docs_dir: docs`, its `nav` written after
  `_Sidebar.md`), VitePress (`docs/` as its source folder), Wiki.js (its Git storage on the repository), or Gitea's or
  Forgejo's wiki (the `--wiki` export pushed to it, as GitHub's).
