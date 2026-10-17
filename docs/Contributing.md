[فارسی](fa/Contributing.md)

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

Each area's rules and the decisions behind them are on its developer page — [Architecture](Architecture.md),
[Conventions](Conventions.md), and the internals of the area you touch — and in the code itself: every class and every
function that is not obvious says in a short comment what it does. In brief:

- **Every change of behaviour comes with its tests**, in the suites' own way ([Testing](Testing.md)): strict, in random
  order, off the network, their rows from `Tests\Support\Fixtures`.
- **The API is described once.** A change to a request or an answer changes `resources/api/openapi.yaml` too, then
  `pnpm api:types`: every HTTP test, and every request a panel's test sends, is checked against it.
- **Nothing without a caller.** Dead code fails the analysis (PHPStan's dead-code check, knip), and a test is no caller.
- **One rule, one place.** A business rule lives in the domain service the bot, the panels and the website all call;
  look for the helper that exists before writing another.
- **A module depends on another only on purpose.** Which modules each module names is one map, `MODULES` in
  `tests/Unit/Core/LayersTest.php`: a new dependency fails the test until the map lists it, with a note on why when it is
  more than a plain use of the other module's rows or services ([Architecture](Architecture.md#modules)).
- **The code explains itself where it is.** A class and a non-obvious function get a short comment saying what they do
  (and why, when that is not obvious); a long function gets a line above each step. No line-by-line comments
  ([Conventions](Conventions.md)).
- **The pages change with the behaviour they describe.** A change an owner, a customer or a website's developer would
  notice updates the page that tells them; a change of how something works updates its developer page. A generated
  page is changed through what it is made from, then `php scripts/docs.php` ([documentation](#documentation)).
- **Where a new piece goes**: [Extending](Extending.md); a new VPN panel, payment gateway, database, mail service or
  captcha: [Drivers](Drivers.md).

## Proposing a change

1. Open an issue first for anything larger than a fix — a feature, a new driver, a change to an API's contract — so its
   shape is agreed before the work. For a bug, say the version (`Application::VERSION`, or the commit), the host, where
   it happens and how to make it happen: the issue forms ask for each.
2. Branch from `main`, keep the change to one subject, and run the checks. A change a shop's owner, an agent, its
   customers or a website's developer would notice gets a line in the English [Releases](Releases.md) page
   (`docs/Releases.md`), under `## Unreleased` at its top — the section a release renames to its version, and publishes
   as its notes. That page is the release notes' source; its Persian page, `docs/fa/Releases.md`, is its translation,
   brought up to date when the version is released ([Releasing](Releasing.md#publishing-it)).
3. Open a pull request that says what changes for the shop's owner, an agent, its customers or a website's developer,
   why, and how it is tested — its template lists the checks. CI must pass.

What you contribute is published under the project's MIT license.

## Language

Everything a user sees — the bots, the panels, the installer, the API's messages — is Persian, by design; code,
comments, console output and these pages are English (their translations aside: [languages](#languages)).

- Persian is written plainly, without Arabic diacritics: no tanwin or harakat (`لطفا`, not `لطفاً`), no hamza-ezafe
  (`نسخه ۳`, not `نسخهٔ ۳`), and `تایید` rather than `تأیید`; the zero-width non-joiner in compounds (`می‌شود`) stays.
- Technical terms keep their Latin form or the transliteration everyone uses (`Webhook`, `Session`, `دیتابیس`, `توکن`),
  never a formal calque. A customer reads «پشتیبانی», never «مدیر».
- What the bot says to a customer is a bot text the owner may reword ([Extending](Extending.md#a-bot-command-or-screen)).
- In the English pages, Persian appears only where it is the product's own words — a button, a message —, quoted in «»
  as the panels show them, or in code as the API sends them.

## Documentation

These pages are the project's one documentation system — there is no separate handbook or changelog: what a
maintainer needs is here or in the code's own comments. They are one tree, `docs/`, and three places read it as it is:
the repository's own view on GitHub, the project's GitHub wiki, and GitHub Pages.

- **The pages** are flat — the wiki has one namespace — GitHub-flavoured Markdown, one subject a page, named
  `Title-Case-With-Dashes.md`: English at the top of `docs/`, each translation in its language's folder
  ([languages](#languages)). `Home.md` is the front page, `_Sidebar.md` the navigation (the wiki and Docsify both read
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
  the shared page, and a request's body field by field at its operation. They are English only.
- **The check** — `php scripts/docs.php --check`, which CI runs — fails while a generated page is stale, a link leads to
  no page or no heading, a page is not in its language's sidebar or its name is not of the tree's form, or an English
  heading would get two anchors, or the release notes (`docs/Releases.md`) have an `@name` outside code — on GitHub a
  release's text links each such name to its GitHub account, notifies it and lists it among the release's
  contributors; it checks the links of the repository's `README.md` too, and holds the translations to the rules of
  [their languages](#languages).
- **The wiki** is published by `.github/workflows/wiki.yml` on every push to the default branch that changes `docs/`:
  it checks out the wiki's repository, runs `php scripts/docs.php --wiki <dir>` — every page, its links written without
  `.md`, a language's pages in its folder, and a page the tree no longer has taken out — and pushes. GitHub makes a
  wiki's repository only once its first page exists, so switch the wiki on (Settings › Features › Wikis) and create one
  page by hand (the Wiki tab's *Create the first page*); the export then replaces it. Until that page exists, a run says
  so in a warning and publishes nothing — run the workflow again by hand (Actions › Wiki › Run workflow) once the page
  is there.
- **GitHub Pages** serves `docs/` as it is, at https://amir-aghajani.github.io/amo-bot/ (the repository's About links
  it): Settings › Pages › Deploy from a branch, the default branch, `/docs`.
  `docs/index.html` is [Docsify](https://docsify.js.org), which renders the Markdown in the browser — its script, its
  theme and its search pinned by version from jsDelivr, with the `_Sidebar.md` of a page's folder as its sidebar and
  `Home.md` as its front page —, and `docs/.nojekyll` keeps GitHub's Jekyll from hiding the `_`-named files. There is
  nothing to build or install; any static file server previews it locally (`npx docsify-cli serve docs`).
- **Any other Markdown documentation system** reads the same files: MkDocs (`docs_dir: docs`, its `nav` written after
  `_Sidebar.md`), VitePress (`docs/` as its source folder), Wiki.js (its Git storage on the repository), or Gitea's or
  Forgejo's wiki (the `--wiki` export pushed to it, as GitHub's).

### Languages

English is the documentation's own language, at the top of `docs/`. Each other language has a folder named by its
code — Persian's is `docs/fa/` — holding the same pages under the same names (`docs/fa/Plans.md` translates
`docs/Plans.md`) and its own `Home.md`, `_Sidebar.md` and `_Footer.md`. The generated references are not translated.

- **A page's other versions** are linked from its first line, a blank line after it: `[فارسی](fa/Plans.md)` atop
  `docs/Plans.md`, `[English](../Plans.md)` atop `docs/fa/Plans.md` — every version the page has, English first, then
  the languages' order, joined by ` · `. `php scripts/docs.php` writes these lines, and takes out a link to a version
  that is gone; never write them by hand.
- **Links** stay within their language: in `docs/fa/`, `[…](Plans.md)` is `docs/fa/Plans.md`. A translated page also
  links to its own English version and to the generated references as `../Page-Name.md`
  (`../Config-Reference.md#database`); an English page links to its translations only by its first line. A language's
  sidebar lists every page of its folder, in the English sidebar's order, and may list the English references
  (`../Config-Reference.md`).
- **Anchors** are GitHub's everywhere — Docsify is given them too (`docs/index.html`): the heading in lower case, each
  space a dash, every mark but letters, digits, `-`, `_` and the zero-width non-joiner dropped (`## روش‌های پرداخت؟` is
  `#روش‌های-پرداخت`), and a second heading of the same words numbered `-1`, then `-2`. The rule that a heading keeps to
  what GitHub and Docsify slug alike holds for English pages only.
- **Right to left.** A right-to-left language's page, sidebar and footer is wrapped, after its first line, in
  `<div dir="rtl" lang="fa">` and `</div>`, each with a blank line between it and the text, so the Markdown inside still
  renders: the wiki and the repository's view lay the page out right to left by it. GitHub's view also gives each
  heading, paragraph, list and code block `dir="auto"` — its direction its first letter's —, so on such a page: a
  heading, a paragraph or a list item starts with a word of the language (where none fits, with `&rlm;`, the invisible
  right-to-left mark); a list has no blank line between or within its items (a code block in an item follows its text
  directly); a code block names its language (```` ```bash ````, ```` ```text ````); and a code span that starts or ends
  with a mark — not a letter or a digit — beside a Latin word, as `--check`, `.env`, `#anchor` and `key()` do, is
  written `<code dir="ltr">--check</code>`, which a right-to-left line would otherwise show as `check--`. A span wrapped
  in the same mark at both ends or in a pair of brackets is judged by what it holds: `/admin/` and `{store}` stay in
  backticks, while `"--check"` and `{ "order": … }` take `dir="ltr"`. A mark beside a Persian word stays in backticks
  too (a Persian message quoted with its full stop): it already shows where it belongs, and `dir="ltr"` would move it.
  The check names each span that needs it, written as it should be. The wiki's export puts each code block of such a
  page in a left-to-right `<div>`; Docsify lays the language's routes out right to left, the sidebar on the right, in
  Vazirmatn.
- **The wiki** has one namespace of page names whatever its folders, so a language's pages go to its folder there,
  under their name with the code before it — `docs/fa/Plans.md` is the wiki's `fa/fa-Plans.md`, its page `fa-Plans` —,
  their links rewritten to those names, beside the folder's own `_Sidebar.md` and `_Footer.md`, which the wiki shows on
  the pages of that folder.
- **Docsify** routes a language under its folder (`#/fa/Plans`): a link resolves from its page's folder, the sidebar is
  the page's folder's, the footer its language's, the search keeps an index a language, and the navbar's switch leads
  to the page's version in each language, or to that language's front page where it has none.
- **Adding a page to a language**: write `docs/fa/Page-Name.md` under the English page's name, add it to
  `docs/fa/_Sidebar.md` where the English sidebar has it, run `php scripts/docs.php` (it writes the first line of both
  versions), then `php scripts/docs.php --check`.
- **Adding a language**: add its code, its name as its readers write it, and its direction to `LANGUAGES` in
  `scripts/docs.php` and to `languages` in `docs/index.html` (there with its search box's words; the check compares the
  two lists), then create `docs/<code>/` with its `Home.md`, `_Sidebar.md` and `_Footer.md`.
- **The check** holds the languages to all this: every folder of `docs/` with pages is a language, with its front page,
  sidebar and footer; a translated page has its English page and is no generated one; each page's first line is the one
  above; and a right-to-left page keeps the rules of its direction. It lists the pages each language has not translated
  yet without failing; `php scripts/docs.php --check --require-translations` fails on each of them instead. CI
  (`.github/workflows/ci.yml`) runs the check with that switch, the Persian translation being complete: a new English
  page, or a new language, fails CI until every page has its translation — so a page comes with its translation in the
  same change.
