[فارسی](fa/Security.md)

# Security

## Reporting a vulnerability

Please do not open a public issue for a weakness that could hurt a shop or its customers. Report it privately through
GitHub's private vulnerability reporting: the repository's **Security** tab › **Report a vulnerability**
([github.com/amir-aghajani/amo-bot/security/advisories/new](https://github.com/amir-aghajani/amo-bot/security/advisories/new)).
Say:

- what it is and where — the panels' API, a panel, a bot, the Store API, the installer, the webhooks, the cron
  address or a payment gateway's callback, the release zip;
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

### PHP itself

A shop runs only on a PHP release without PHP's known security holes: on each branch it supports, the release that
closed the latest ones, or a later one. The list is `Support\PhpVersion::MINIMUMS`: 8.3.36, 8.4.27 and 8.5.12 since
21 October 2026, when PHP closed two critical holes that let anyone who can reach a site run their own code there
(CVE-2026-109618 and CVE-2026-109619). PHP 8.2 is no branch of the shop's since 0.7.1. On an older release:

- the installer stops;
- the panel's update installs no release, going by the list each release carries in its signed `release.json`;
- a shop already running on one says so in a strip over every page of the owner's panel;
- below 8.3.36, the shop does not start at all.

When PHP announces a security release, the next AmoBot release raises the list
([Releasing](Releasing.md#php-security-releases)).

## How a shop is kept safe

What the code does by itself, in short. [Architecture](Architecture.md#failures-and-hardening) says how, and what an
owner must do is the [checklist](Running-In-Production.md#checklist) of running in production.

### What PHP answers

- PHP answers JSON — and the pictures it keeps, as their bytes, and one small page where an online gateway sends a
  customer's browser back — and nothing else. Every answer has a sandboxing Content-Security-Policy, `nosniff`, no
  framing, and no caching (a picture the panels show is kept a few minutes, in the viewer's browser alone). The panels'
  pages and the mini app's carry a policy of their own from the build, and the gateway's return page one that loads nothing and runs no
  script.
- A failure's details never reach a visitor. A JSON body is read to 1 MiB at most, and an upload to its own limit.
- Every guard belongs to its route group: an address spelled another way is the same route behind the same guards (a
  test walks every route). The panels' writes need a header a page of another site cannot send; the websites' API takes
  a bearer token, no cookie, and answers CORS for the website's own origins alone.

### Signing in

- Every sign-in — the owner's, an agent's link, a website customer's password, code or provider — is limited per
  address and per account, counted before it is judged, and fails closed when the limit cannot be counted. A texted
  code is counted against its number only while one waits for it, so a stranger guessing at a number locks its holder
  out of nothing; wrong codes stop its codes being tried, never a new one going.
- A code by SMS is weaker than Telegram or Google — a SIM swapped, or a number the carrier gave someone else, reads
  it —: an account with two-factor sign-in is asked its second step after it too, it proves no such account again on
  its own, and a sign-in by it alone is no strong one. Of two accounts holding one number it signs in to one and offers
  the other as a merge, never merging unasked.
- A change to how a customer's account is signed in to asks for a recent sign-in, and is told on every channel the
  account has. Handing a service to another customer on the website asks for a recent sign-in too, and so does setting
  up the mini app's fingerprint ([the Telegram mini app](#the-telegram-mini-app)).
- A fresh upload's installer asks for a key only the host's files hold, and a lost panel login comes back through a key
  written to the host's files — never through the web alone.
- Moving the Bot API's address — where every bot's token goes — asks for the owner's current password, and a wrong one
  counts as a failed sign-in.

### The shop's admins on its website

The shop's admins on its website ([the shop's admins](Store-API-Admins.md)) are shut out until the shop lets them in.
They are asked for a strong sign-in (not a password, nor a code by SMS, alone) by default, and for a sign-in of the last
12 hours always.
They get nothing beyond the daily work but what the shop grants, and each grant, like approving a payment, asks for a
sign-in of the last 15 minutes. They never decide about themselves, and never touch an admin's or an agent's account:
they cannot put one in a customer group, nor switch a group an admin is in to «همکار», whose members buy at the agents'
prices. Making or changing the customer groups, and putting a customer in a «همکار» group, need the catalogue's grant,
as the plans do; and two accounts merged never bring a «همکار» group into an admin's or an agent's account. Every
change they ask for is logged with them on the line. These guards are each admin's own — two admins can
act for each other —, so the role is for people the owner trusts. The shop's configuration is the panels' alone.

### Secrets

- Secrets at rest — the servers' panel credentials and their SOCKS5 proxies' passwords, the agents' bots' tokens, the
  payment methods' tokens and keys, the websites' secrets, customers' two-factor secrets — are encrypted with `APP_KEY`.
  Short codes are kept as keyed hashes, passwords as bcrypt hashes, customers' session tokens and the mini app's
  fingerprint secrets as their sha256.
- A kept panel secret goes only to the panel's own origin, through the same proxy and with the same TLS check; a kept
  proxy password only to the same proxy host and port: changed, either must be typed again
  ([Servers](Servers.md#adding-a-server)).
- A form shows a kept secret as dots: a token or key of 12 characters or more with its last four (a bot's token with
  its bot's id too), a shorter one and every password with none.
- The app takes secrets out of every line it writes to its log and of every failure's details: bot tokens, the secret
  part of the machine addresses (the webhooks, the cron, the payment callbacks), a login written into an address (a
  proxy's), a key in an address's query. PHP's own warnings and fatal errors, which PHP itself writes to
  `storage/logs/php-errors.log`, are not cleaned: read that file before you share it.
- Every file the app writes — `config.php`, the install and recovery keys (`storage/install-key.txt`,
  `storage/recovery-key.txt`), the pictures it keeps, the log, the sessions' folder — is its
  owner's alone (`0600`, its folders `0700`) where PHP runs as the account that owns the app (PHP-FPM, suEXEC, LSAPI):
  a shared host has other accounts. Where it runs as a server-wide user — Apache's `mod_php` — they are `0644` and
  `0755`, so the owner can still open them in the host's File Manager; there every account's PHP is one user, and the
  other accounts can read them ([file ownership](Running-In-Production.md#file-ownership)).
- A backup is signed with a key drawn from `APP_KEY`, over every line: only a file this installation made, unchanged,
  is restored, so nobody — an agent with their own shop's file among them — writes into one rows of another shop's or
  of the owner's; a shop's file restores only in that shop. It is not encrypted beyond the columns that are: its
  customers' data reads as it is, so it is kept in `storage/backups/`, which the web never reaches, and goes only to
  the report group ([Backups](Backups.md)).

### Payments

- An online payment gateway's callback is never taken at its word. Each payment has an address of its own with a
  secret in it (kept out of the log), and a call there only makes the shop ask the gateway itself — a few times a minute
  at most for one payment, then answered with what the shop already knows. A payment is settled only on what the
  gateway says when asked, and one whose answer does not fit it (another order or amount) is held for support, never
  settled — as is one the gateway closes after it said money had come in on it.
- Support's money actions are done once: a wallet credit or debit, an agent's traffic set right and a ticket answer
  carry a request key (`Idempotency-Key`), so a press sent again after a lost answer changes nothing more.
- A gateway that answers no question (Variza) is believed only through its webhook's signature: an HMAC-SHA256 of the
  raw body with the method's webhook secret (kept encrypted), compared in constant time. A webhook no method signs is
  refused and changes nothing; one signed for another sum than the payment's is held for support. The webhook's address
  answers `404` for a gateway that takes no webhook.

### Telegram, uploads and outgoing calls

- The Telegram webhooks are each bot's own secret address, the secret checked before anything else. An agent's bot
  takes a budget of updates of its own, and a chat that floods a bot is left unanswered while it keeps on. In a report
  group only presses, commands and replies to the bot count toward that, so the members' own chatter never silences an
  admin's reply.
- Uploads are judged by their bytes, held to a size, written again without anything of the sender's file but its
  pixels (where PHP has `gd`), kept outside the web root and handed back as attachments unless they are pictures the
  panels show.
- A server's SOCKS5 proxy carries that panel's requests alone: Telegram, mail and every other outgoing call never go
  through it.

### The Telegram mini app

- A customer of the shop's mini app is signed in by the launch data Telegram hands it, checked as Telegram's
  documentation says: an HMAC-SHA256 over its fields under a key made of the bot's token, compared in constant time.
  Only Telegram, which holds the token, can sign it; the page only carries it. It signs in for a day, but the session is
  proven from when Telegram made it, so an old launch changes nothing a recent sign-in guards. Every refusal counts as a
  failed sign-in.
- A fingerprint or face proves a way in again through a secret the shop gives one session and Telegram keeps behind the
  device's biometrics: it is made only on a recent sign-in, kept as its sha256, proves that session alone, and leaves
  how it was signed in as it was. The shop never sees a fingerprint or a face. It is the mini app's alone: under a
  website's key it is no route, and neither it nor the launch data proves a way in again there — a website would keep
  the secret beside the bearer token, a pair that keeps the session recent for as long as it lasts.
- Under the mini app's store key no other site may call the API (no CORS), no captcha or admin API is there, and what
  only a website has answers as no route at all. Sessions belong to the shop, not to a door, so the website's admin API
  refuses a session the mini app signed in under the website's key too, for its whole life: the mini app is the
  customer side alone.
- It is the bot's own door, and the bot answers nothing before its gates: through it, the server itself refuses an
  order, a gift code and a service added by its link while the shop asks every customer for their number and this one
  has none, or has not joined the shop's channels — whatever the app shows.
- Its pages load no third-party script: Telegram's own is bundled into the build, and the build's
  Content-Security-Policy lets them talk to the shop's own address alone. Its folder may be framed by Telegram's web
  client and by the site itself — the panels' «پیش‌نمایش» of the app —
  (`frame-ancestors 'self' https://web.telegram.org`) and by nobody else; every other page may be framed by nobody.
