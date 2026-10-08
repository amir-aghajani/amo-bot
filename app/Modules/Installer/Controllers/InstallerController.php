<?php

declare(strict_types=1);

namespace App\Modules\Installer\Controllers;

use App\Core\Http\ApiController;
use App\Modules\Installer\Services\Installer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /api/install — the web installer's steps, for the owner's installer page. Its route group is open only until the shop
 * is installed (`installer.open`) and only with the install key (InstallKeyMiddleware). Every step answers with where
 * the installation stands now, or a 422 with what to fix.
 */
final class InstallerController extends ApiController
{
    public function __construct(private readonly Installer $installer) {}

    /** GET /api/install */
    public function status(Request $request, Response $response): Response
    {
        return $this->json($response, $this->installer->status());
    }

    /** POST /api/install/database — {driver, …its form's fields}: a secret left blank is empty */
    public function database(Request $request, Response $response): Response
    {
        $this->installer->saveDatabase($this->input($request));

        return $this->status($request, $response);
    }

    /** POST /api/install/tables — the tables, on the database the previous step wrote */
    public function tables(Request $request, Response $response): Response
    {
        $this->installer->createTables();

        return $this->status($request, $response);
    }

    /** POST /api/install/admin — {username, password, password_confirmation} */
    public function admin(Request $request, Response $response): Response
    {
        $this->installer->saveAdmin($this->input($request));

        return $this->status($request, $response);
    }

    /** POST /api/install/site — {name, url, token?} */
    public function site(Request $request, Response $response): Response
    {
        $this->installer->saveSite($this->input($request));

        return $this->status($request, $response);
    }

    /** POST /api/install/finish */
    public function finish(Request $request, Response $response): Response
    {
        $this->installer->finish();

        return $this->json($response, ['installed' => true]);
    }
}
