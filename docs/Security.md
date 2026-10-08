# Security

## Reporting a vulnerability

Please do not open a public issue for a weakness that could hurt a shop or its customers. Report it privately through
GitHub's private vulnerability reporting: the repository's **Security** tab › **Report a vulnerability**
([github.com/amir-aghajani/amo-bot/security/advisories/new](https://github.com/amir-aghajani/amo-bot/security/advisories/new)).
Say:

- what it is and where — the panels' API, a panel, a bot, the Store API, the installer, the webhooks or the cron
  address, the release zip;
- the version (`Application::VERSION`, or the commit);
- how to reproduce it, and what an attacker gains.

The report stays private while it is fixed; the fix goes into a release, and the report is published once shops can
upgrade to it.

## What is in scope

The code of this repository and the release zip built from it. Not in scope: a shop's own host and how it is set up (a
document root that serves the whole project, a `config.php` shared or left writable to everyone), the VPN panels,
Telegram, Google and the other services AmoBot talks to, and social engineering.

## Supported versions

AmoBot is pre-release: fixes land on the main branch and in the next release; there are no older release lines to
patch yet.

## How a shop is kept safe

What the code does by itself, in short — [Running in production](Running-In-Production.md#what-keeps-it-safe) and
[Architecture](Architecture.md#failures-and-hardening) have more:

- PHP answers JSON only, every answer with a sandboxing Content-Security-Policy, `nosniff`, no framing and no caching;
  the panels' pages carry a policy of their own from the build. A failure's details never reach a visitor.
- Every guard is the route group's: an address spelled another way is the same route behind the same guards (a test
  walks every route). The panels' writes need a header a page of another site cannot send; the websites' API takes a
  bearer token, no cookie, and answers CORS for the website's own origins alone.
- Every sign-in — the owner's, an agent's link, a website customer's password, code or provider — is throttled per
  address and per account, counted before it is judged, and fails closed when the throttle cannot count. What changes
  how a customer's account is signed in to asks a recent sign-in, and is told on every door the account has.
- The shop's admins on its website are shut out until the shop lets them in, asked a strong sign-in (not a password
  alone) by default and a sign-in of the last 12 hours always, given nothing beyond the daily work but what the shop
  grants — each grant, and approving a payment, asking a sign-in of the last 15 minutes —, never let decide about
  themselves or touch an admin's or an agent's account, and every change they ask is logged with them on the line
  ([the shop's admins](Store-API-Admins.md)). Those guards are each admin's own: two admins can act for each other, so
  the role is for people the owner trusts. The shop's configuration is the panels' alone.
- Moving the Bot API's address — where every bot's token goes — asks the owner's current password, a wrong one counted
  as a failed sign-in.
- Secrets at rest — the servers' panel credentials, the agents' bots' tokens, the websites' secrets, customers'
  two-factor secrets — are encrypted with `APP_KEY`; short codes are kept as keyed hashes; passwords as bcrypt hashes.
  Secrets are taken out of every log line and error.
- A fresh upload's installer asks for a key only the host's files hold, and a lost panel login comes back through a key
  written to the host's files — never through the web alone.
- Every file the app writes — `config.php`, the host keys, the pictures it keeps, the log, the sessions' folder — is its
  owner's alone (`0600`, its folders `0700`) where PHP runs as the account that owns the app (PHP-FPM, suEXEC, LSAPI): a
  shared host has other accounts. Where it runs as a server-wide user — Apache's `mod_php` — they are `0644` and `0755`,
  so the owner can still open them in the host's File Manager; there every account's PHP is one user, and the other
  accounts can read them ([file ownership](Running-In-Production.md#file-ownership)).
- Uploads are judged by their bytes, held to a size, written again without anything of the sender's file but its
  pixels, kept outside the web root and handed back as attachments unless they are pictures the panel shows.
- The Telegram webhooks are each bot's own secret address, the secret checked before anything else; an agent's bot
  takes a budget of updates of its own, and a chat that floods a bot is left unanswered while it keeps on.
