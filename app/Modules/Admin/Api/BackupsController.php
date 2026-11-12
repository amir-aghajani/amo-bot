<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Core\Http\LongRunning;
use App\Modules\Auth\Principal;
use App\Modules\Backups\Backups;
use App\Modules\Backups\BackupScope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * «پشتیبان‌گیری», both panels: the owner in the main shop backs up and restores the whole installation, any other panel
 * its shop alone (BackupScope::for()). A backup downloads as it is made; a file to restore is uploaded and described
 * first (inspect), then restored by its token — the state before kept to download.
 */
final class BackupsController extends ApiController
{
    /** Seconds a backup made, checked or restored may take: a large shop's on a slow host — the panel waits as long. */
    private const TIME_LIMIT = 15 * 60;

    public function __construct(
        private readonly Backups $backups,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /** GET /backups */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['backups' => $this->backups->status(BackupScope::for(Principal::of($request)))]);
    }

    /** PUT /backups/auto — {enabled}: the shop's backup to its report group, or not */
    public function auto(Request $request, Response $response): Response
    {
        $this->backups->sendToGroup($this->switch($request, 'enabled'));

        return $this->show($request, $response);
    }

    /** GET /backups/download — a backup made now */
    public function download(Request $request, Response $response): Response
    {
        LongRunning::keepGoing(self::TIME_LIMIT);
        $made = $this->backups->make(BackupScope::for(Principal::of($request)));

        return $this->file($response, $made['path'], $made['name']);
    }

    /** POST /backups/inspect — multipart `file`: checked and described, kept under a token to restore */
    public function inspect(Request $request, Response $response): Response
    {
        LongRunning::keepGoing(self::TIME_LIMIT);

        return $this->json($response, ['inspection' => $this->backups->inspect($this->uploadedFile($request), BackupScope::for(Principal::of($request)))]);
    }

    /** POST /backups/restore — {token, confirm: true} */
    public function restore(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        if (($input['confirm'] ?? null) !== true) {
            throw ValidationException::on('confirm', 'بازگردانی را تایید کنید؛ هرچه از زمان این پشتیبان تغییر کرده از دست می‌رود.');
        }
        // Begun, it ends — committed or rolled back — even when the admin closes the page.
        LongRunning::keepGoing(self::TIME_LIMIT);

        return $this->json($response, ['restore' => $this->backups->restore((string) ($input['token'] ?? ''), BackupScope::for(Principal::of($request)))]);
    }

    /** GET /backups/kept/{name} — a state kept from before a restore */
    /** @param array{name: string} $args */
    public function kept(Request $request, Response $response, array $args): Response
    {
        $path = $this->backups->keptPath(BackupScope::for(Principal::of($request)), $args['name']) ?? throw new HttpNotFoundException($request);

        return $this->file($response, $path, $args['name']);
    }

    /** A backup file to save, streamed from disk. */
    private function file(Response $response, string $path, string $name): Response
    {
        return $response
            ->withBody($this->streams->createStreamFromFile($path, 'rb'))
            ->withHeader('Content-Type', 'application/gzip')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
    }
}
