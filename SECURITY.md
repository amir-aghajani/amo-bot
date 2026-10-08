# Security policy

Please do not open a public issue, discussion or pull request for a weakness that could hurt a shop or its customers.
Report it privately through GitHub's private vulnerability reporting: the repository's **Security** tab › **Report a
vulnerability** — [github.com/amir-aghajani/amo-bot/security/advisories/new](https://github.com/amir-aghajani/amo-bot/security/advisories/new).
Only the maintainers see the report. (Should that button be missing, open an issue asking for a private way to report —
with no details of the weakness in it.)

Say what it is and where — the panels or their API, a bot, the Store API, the web installer, the webhooks or the cron
address, the release zip —, the version (`Application::VERSION`, or the commit), how to reproduce it, and what an
attacker gains. The report stays private while it is fixed; the fix goes into a release, and the report is published
once shops can upgrade to it.

AmoBot is pre-release: fixes land on the main branch and in the next release, and there are no older release lines to
patch. What is in scope, and how a shop is kept safe: [docs/Security.md](docs/Security.md).
