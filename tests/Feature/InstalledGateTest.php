<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\Repository as Config;
use App\Core\Http\Middleware\InstalledMiddleware;
use App\Support\LocalTime;
use Tests\HttpTestCase;

/**
 * What tells a panel which shop it is answers whether the shop is installed or not — /api/app (its name, whether to
 * open the installer, its time zone), /health, the site's root — and an address spelled another way is the same route
 * behind the same guard. (That every other route waits for the installation, and that the installer is gone
 * afterwards, is RouteGuardsTest's walk over the router.)
 */
final class InstalledGateTest extends HttpTestCase
{
    public function testWhatTellsAPanelWhichShopItIsAnswersEitherWay(): void
    {
        foreach ([false, true] as $installed) {
            $this->installed($installed);

            self::assertSame(['name' => $this->service(Config::class)->get('app.name'), 'installed' => $installed, 'timezone' => 'UTC'], $this->decode($this->get('/api/app')), 'the panel opens its installer, or its login');
            self::assertSame($installed, $this->decode($this->get('/health'))['installed']);

            $home = $this->get('/');
            self::assertSame(302, $home->getStatusCode());
            self::assertSame('/admin/', $home->getHeaderLine('Location'), 'the site\'s root opens the owner\'s panel');
        }
    }

    public function testThePanelIsToldTheZoneTheShopsTimesReadIn(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            self::assertSame('Asia/Tehran', $this->decode($this->get('/api/app'))['timezone'], 'every time the panel shows is in it, as the bot says them');
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testAnEncodedAddressIsTheSameRouteBehindTheSameGuard(): void
    {
        // Slim routes on the decoded path: "/api/%69nstall" is "/api/install" to it.
        $this->installed(false);
        foreach ([['GET', '/api/%61dmin/auth/me'], ['POST', '/webhooks/%74elegram/anything'], ['GET', '/%63ron/anything']] as [$method, $path]) {
            // No panel spells an address so: the description's `{panel}` is admin or agent, as written.
            $response = $this->unchecked()->json($method, $path);

            self::assertSame(503, $response->getStatusCode(), $path);
            self::assertSame(InstalledMiddleware::NOT_INSTALLED, $this->decode($response)['message'], $path);
        }

        $this->installed(true);
        foreach (['/api/%69nstall', '/api/install/%66inish', '/api/%69nstall/finish'] as $path) {
            $response = $this->json(str_ends_with(rawurldecode($path), 'finish') ? 'POST' : 'GET', $path);

            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame(InstalledMiddleware::INSTALLER_GONE, $this->decode($response)['message'], $path);
        }
    }
}
