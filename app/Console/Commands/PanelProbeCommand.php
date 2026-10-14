<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Exceptions\ValidationException;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Services\ServerService;
use App\Modules\Providers\Support\PanelConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The add-server form's probe from a shell (ServerService::probeInput()): connects to a panel through its connector —
 * through a SOCKS5 proxy with `--proxy` — and prints its status, inbounds and subscription server. Nothing is stored.
 */
#[AsCommand(name: 'panel:probe', description: 'Connect to a panel through its connector and print its status, inbounds and subscription server')]
final class PanelProbeCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServerService $servers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('driver', InputArgument::REQUIRED, 'The connector: ' . implode(', ', array_map(static fn(PanelDriver $driver): string => $driver->key(), $this->providers->all())))
            ->addArgument('url', InputArgument::REQUIRED, 'The panel\'s address, as the add-server form takes it (3x-ui: with its web base path)')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'API token (or key)')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Admin username (instead of a token)')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Admin password')
            ->addOption('totp', null, InputOption::VALUE_REQUIRED, 'Base32 TOTP secret, for a 3x-ui panel with two-factor login on')
            ->addOption('insecure', 'k', InputOption::VALUE_NONE, 'Do not verify the TLS certificate')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Reach the panel through a SOCKS5 proxy: socks5h://[user:password@]host[:port] (port 1080 if left out)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $driver = (string) $input->getArgument('driver');
        if (!$this->providers->has($driver)) {
            $io->error("No connector \"{$driver}\".");

            return Command::INVALID;
        }

        $token = (string) $input->getOption('token');
        $proxy = self::proxy((string) $input->getOption('proxy'));
        if ($proxy === null) {
            $io->error('--proxy takes a SOCKS5 proxy\'s address: socks5h://[user:password@]host[:port].');

            return Command::INVALID;
        }
        try {
            // A server's form on the connector, as the add-server form sends it — the fields its connector has no use for
            // (3x-ui's two-factor secret elsewhere) left unread.
            $probe = $this->servers->probeInput([
                'driver' => $driver,
                'name' => 'panel:probe',
                'base_url' => (string) $input->getArgument('url'),
                'auth_mode' => $token !== '' ? 'token' : 'password',
                'api_token' => $token,
                'username' => (string) $input->getOption('username'),
                'password' => (string) $input->getOption('password'),
                'totp_secret' => (string) $input->getOption('totp'),
                'verify_tls' => !$input->getOption('insecure'),
                'timeout' => PanelConnection::DEFAULT_TIMEOUT,
                'is_active' => true,
            ] + $proxy);
        } catch (ValidationException $e) {
            $io->error(array_merge(...array_values($e->errors())));

            return Command::INVALID;
        }

        if (!$probe['ok']) {
            $io->error(array_filter([$probe['error'], $probe['error_detail']]));

            return Command::FAILURE;
        }

        $status = $probe['status'];
        $io->section('Server');
        $io->definitionList(...($status === null ? [['Status' => 'not reported']] : [
            ['Core' => trim(($status['core_name'] ?? 'core') . ' ' . $status['core_state'] . ' ' . $status['core_version'])],
            ['CPU' => sprintf('%.1f%%', $status['cpu_percent'])],
            ['Memory' => Helper::formatMemory((int) $status['memory_used']) . ' / ' . Helper::formatMemory((int) $status['memory_total'])],
            ['Disk' => Helper::formatMemory((int) $status['disk_used']) . ' / ' . Helper::formatMemory((int) $status['disk_total'])],
        ]));

        $io->section('Inbounds');
        if ($probe['inbounds'] === null) {
            $io->warning('The panel could not list them.');
        } elseif ($probe['inbounds'] === []) {
            $io->warning('None on this panel yet.');
        } else {
            $io->table(['Key', 'Remark', 'Protocol', 'Port', 'Enabled', 'Clients'], array_map(static fn(array $inbound): array => [
                $inbound['remote_key'],
                $inbound['remark'],
                $inbound['protocol'] ?? '-',
                $inbound['port'] ?? '-',
                $inbound['enabled'] ? 'yes' : 'no',
                $inbound['client_count'],
            ], $probe['inbounds']));
        }

        $io->section('Subscription server');
        $io->writeln(match ($probe['serves_subscriptions']) {
            true => 'Serving links.',
            false => 'Off: customers would get no link, so the server could not be sold.',
            null => 'Could not be asked.',
        });

        $io->success('Panel reachable and credentials accepted.');

        return Command::SUCCESS;
    }

    /**
     * The form's proxy fields from `--proxy` (socks5h://, socks5:// or no scheme; the port 1080 when left out, the login
     * URL-decoded) — the proxy switched off when there is none; null for another kind of address. The form checks the
     * rest as the add-server form's.
     *
     * @return array<string, mixed>|null
     */
    private static function proxy(string $option): ?array
    {
        if ($option === '') {
            return [PanelConnection::PROXY => false];
        }

        $parts = parse_url(str_contains($option, '://') ? $option : 'socks5h://' . $option);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['socks5', 'socks5h'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }
        // Nothing after the port: a proxy has no path, query or fragment.
        if (($parts['path'] ?? '/') !== '/' || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return [
            PanelConnection::PROXY => true,
            'proxy_host' => $parts['host'],
            'proxy_port' => $parts['port'] ?? PanelConnection::PROXY_PORT,
            'proxy_username' => rawurldecode($parts['user'] ?? ''),
            'proxy_password' => rawurldecode($parts['pass'] ?? ''),
        ];
    }
}
