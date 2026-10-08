<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PanelProbeCommand;
use App\Modules\Providers\Models\Server;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\DatabaseTestCase;

/**
 * `panel:probe <driver> <url>`: the add-server form's probe from a shell, through any connector — what the panel answers
 * (its status, its inbounds, whether it serves subscription links), nothing stored. A connector that is not there, an
 * address the connector refuses and a panel that refuses the shop are each said, with an exit code a script can read.
 */
final class PanelProbeCommandTest extends DatabaseTestCase
{
    public function testA3xUiPanelIsProbedWithAToken(): void
    {
        $this->panelHttp()->healthyPanel();

        $probe = $this->probe(['driver' => '3x-ui', 'url' => 'https://panel.example:2053/base', '--token' => 'tok-1']);

        self::assertSame(Command::SUCCESS, $probe->getStatusCode());
        $output = $probe->getDisplay();
        self::assertStringContainsString('Xray running v25.10.31', $output);
        self::assertMatchesRegularExpression('/1\s+VLESS\s+vless\s+443\s+yes/', $output, 'the inbounds, as the panel lists them');
        self::assertStringContainsString('Serving links.', $output);
        self::assertSame('Bearer tok-1', $this->panelHttp()->request(0)->getHeaderLine('Authorization'));
        self::assertSame(0, Server::query()->count(), 'nothing is stored');
    }

    public function testAnyConnectorIsProbedTheSameWayWithAnAdminsLogin(): void
    {
        $this->panelHttp()->raw(
            self::json(['access_token' => 'jwt', 'token_type' => 'bearer']),
            self::json(['users' => [], 'total' => 0]),
            self::json(['version' => '5.4.1', 'uptime_seconds' => 60, 'mem_total' => 4096, 'mem_used' => 1024, 'disk_total' => 8192, 'disk_used' => 2048, 'cpu_cores' => 2, 'cpu_usage' => 3.5]),
            self::json(['groups' => [['id' => 3, 'name' => 'VIP', 'inbound_tags' => ['VLESS TCP'], 'is_disabled' => false, 'total_users' => 4]], 'total' => 1]),
        );

        $probe = $this->probe(['driver' => 'pasarguard', 'url' => 'https://pg.example:8000', '--username' => 'admin', '--password' => 'pw']);

        self::assertSame(Command::SUCCESS, $probe->getStatusCode(), $probe->getDisplay());
        self::assertSame(['POST /api/admin/token', 'GET /api/users', 'GET /api/system', 'GET /api/groups'], $this->panelHttp()->calls());
        self::assertMatchesRegularExpression('/3\s+VIP/', $probe->getDisplay(), 'its groups are the inbounds');
        self::assertStringContainsString('Serving links.', $probe->getDisplay());
    }

    public function testAConnectorThatIsNotThereIsAnInvalidCall(): void
    {
        $probe = $this->probe(['driver' => 'marzban', 'url' => 'https://panel.example', '--token' => 't']);

        self::assertSame(Command::INVALID, $probe->getStatusCode());
        self::assertStringContainsString('No connector "marzban".', $probe->getDisplay());
        self::assertSame([], $this->panelHttp()->calls());
    }

    public function testAnAddressTheConnectorRefusesIsAnInvalidCallSaidInItsWords(): void
    {
        $probe = $this->probe(['driver' => '3x-ui', 'url' => 'panel.example', '--token' => 't']);

        self::assertSame(Command::INVALID, $probe->getStatusCode());
        self::assertStringContainsString('http://', $probe->getDisplay(), 'what the form would say about the address');
        self::assertSame([], $this->panelHttp()->calls());
    }

    public function testAPanelThatRefusesTheShopFailsWithTheDiagnosis(): void
    {
        $this->panelHttp()->raw(new Response(401));

        $probe = $this->probe(['driver' => '3x-ui', 'url' => 'https://panel.example:2053/base', '--token' => 'expired']);

        self::assertSame(Command::FAILURE, $probe->getStatusCode());
        self::assertStringContainsString('Settings → Security → API Token', $probe->getDisplay(), 'where on the panel a token is made');
    }

    /** @param array<string, mixed> $input */
    private function probe(array $input): CommandTester
    {
        $tester = new CommandTester($this->service(PanelProbeCommand::class));
        $tester->execute($input);

        return $tester;
    }

    private static function json(mixed $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
