[فارسی](fa/Console-Commands.md)

# Console commands

This page is for those who have a shell: a developer, or a shop whose host has a terminal (cPanel › *Terminal*, or SSH).
A shop on shared hosting needs none of these commands — the web installer, the panel's settings and the host's cron do
their work ([Installation](Installation.md)), and [Upgrading](Upgrading.md) says what an upgrade asks.

`php bin/console` lists the commands, and `php bin/console help <command>` gives each one's options. A command run in
the host's terminal, or from its cron, runs as your account: where PHP runs as another user (Apache's `mod_php`), the
files it writes would be another user's ([file ownership](Running-In-Production.md#file-ownership)).

| Command | What it does |
|---|---|
| `schedule:run` | Run the scheduled tasks that are due — the cron's command ([the scheduler](Running-In-Production.md#the-scheduler)). `--force` runs every task whatever its interval |
| `bot:webhook:set` | Register every bot's webhook — the main bot's at `APP_URL/webhooks/telegram/<secret>`, each agent's at an address of its own; the panel's «ثبت Webhook». `--url` uses another public base address (`https://` too); `--drop-pending` discards the updates queued meanwhile |
| `bot:webhook:delete` | Remove every bot's webhook — the panel's «برداشتن Webhook». `--drop-pending` discards the queued updates |
| `bot:info` | Show every bot's identity and webhook state, as Telegram reports them |
| `bot:poll` | Receive updates by long polling, for every bot at once, and run the scheduler about once a minute — a developer's way to run the bots ([Development](Development.md)); a shop on a host runs on webhooks. `--watch` supervises a worker and restarts it when the code or `config.php` changes or it crashes; `--no-schedule` leaves the scheduler to a cron; `--once` fetches one batch from every bot and stops; `--drop-pending` skips the updates queued before it started |
| `panel:probe` | Connect to a VPN panel through its connector and print its status, inbounds and subscription server — the add-server form's test, from a shell: `panel:probe 3x-ui <url> -t <token>`, or `-u <username> -p <password>` (with `--totp <secret>` for a 3x-ui panel with two-factor login); `-k` skips the TLS check for a self-signed certificate; `--proxy socks5h://[user:password@]host[:port]` reaches the panel through a SOCKS5 proxy (port 1080 when left out), as a server's «اتصال از طریق پراکسی SOCKS5» does. Any connector: `panel:probe pasarguard …` |
| `db:rebuild` | A developer's: follow an edited `database/schema.php` — every table made again, its rows carried over by column name. Asks first; `--force` does not. Nothing may write meanwhile: stop the bots' updates and the scheduler first. An installed shop never runs it: a release's upgrades bring its database along ([Upgrading](Upgrading.md#the-databases-upgrades)) |

`bot:webhook:set`, `bot:webhook:delete` and `bot:info` go through every bot the shop serves — the main one, and every
agent's whose agency stands and who handed their bot over —, each in its own shop. One bot failing keeps none of the
others from its turn, and each one's outcome is printed.

`bot:poll` takes the webhooks down as it starts. Setting the webhooks while it runs stops it (Telegram then sends to the
webhook), but `--watch` starts it again, and it takes them down again: stop a supervised poller for good first.

`panel:probe` says a refusal or a failure in the add-server form's own words — Persian, as the panel says them —; the
rest of what the commands print is English.

Adding a command: [Extending](Extending.md#a-console-command).
