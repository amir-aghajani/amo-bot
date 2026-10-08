# Console commands

`php bin/console` lists them; `php bin/console help <command>` says the rest. A shop on its host needs none of them for
its daily work — the web installer, the panel's settings and the host's cron (or the cron address) do it; [Upgrading](Upgrading.md)
says what an upgrade asks. They are a developer's tools, and the shop's where its host has a terminal (cPanel ›
*Terminal*): a command run there, or from the cron, runs as your account
([file ownership](Running-In-Production.md#file-ownership)).

| Command | What it does |
|---|---|
| `db:rebuild` | Follow an edited `database/schema.php`: every table made again, its rows carried over by column name. Asks first; `--force` does not. Nothing may write meanwhile: stop the bots' updates and the scheduler first |
| `bot:poll` | Receive updates by long polling, for every bot at once, and run the scheduler about once a minute — a developer's way to run the bots ([Development](Development.md)); a shop on a host runs on webhooks. `--watch` supervises a worker and restarts it when the code or `config.php` changes or it crashes; `--no-schedule` leaves the scheduler to a cron; `--once` fetches one batch from every bot and stops; `--drop-pending` skips the updates queued before it started |
| `bot:webhook:set` | Register every bot's webhook — the main bot's at `APP_URL/webhooks/telegram/<secret>`, each agent's at an address of its own. `--url` uses another public base address (`https://` too); `--drop-pending` discards the updates queued meanwhile |
| `bot:webhook:delete` | Remove every bot's webhook (`bot:poll` does it as it starts). `--drop-pending` discards the queued updates |
| `bot:info` | Show every bot's identity and webhook state, as Telegram reports them |
| `panel:probe` | Connect to a VPN panel through its connector and print its status, inbounds and subscription server — the add-server probe, from a shell: `panel:probe 3x-ui <url> -t <token>`, or `-u <username> -p <password>` (with `--totp <secret>` for a 3x-ui panel with two-factor login); `-k` skips the TLS check for a self-signed certificate. Any connector: `panel:probe pasarguard …` |
| `schedule:run` | Run the scheduled tasks that are due — the cron's command ([the scheduler](Running-In-Production.md#the-scheduler)). `--force` runs every task whatever its interval |

`bot:webhook:set`, `bot:webhook:delete` and `bot:info` go through every bot the shop serves — the main one, and every
agent's whose agency stands and who handed their bot over —, each in its own shop; one bot failing keeps none of the
others from its turn, and each one's outcome is printed. `panel:probe` says a refusal or a failure in the add-server
form's own words — Persian, as the panel says them —; the rest of what the commands print is English.

Adding a command: [Extending](Extending.md#a-console-command).
