<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Security\Encrypter;
use App\Modules\Providers\Models\Server;
use Tests\DatabaseTestCase;

/**
 * A secret at rest (a panel's token, a bot's) is stored encrypted and read back as it was — and one that no longer
 * decrypts (APP_KEY changed since) reads as empty with a warning in the log, never takes the shop down.
 */
final class EncryptedCastTest extends DatabaseTestCase
{
    public function testASecretIsStoredEncryptedAndReadBack(): void
    {
        $server = $this->panelServer(overrides: ['api_token' => 'tok-secret']);

        $stored = (string) $this->db()->table('servers')->where('id', $server->id)->value('api_token');
        self::assertNotSame('tok-secret', $stored, 'never in the clear');
        self::assertSame('tok-secret', Server::query()->findOrFail($server->id)->api_token);
    }

    public function testASecretThatNoLongerDecryptsReadsAsEmptyAndIsLogged(): void
    {
        $logs = $this->logs();
        $server = $this->panelServer();
        $this->db()->table('servers')->where('id', $server->id)->update(['api_token' => Encrypter::fromKey(Encrypter::generateKey())->encrypt('tok-old')]);

        self::assertNull(Server::query()->findOrFail($server->id)->api_token);
        self::assertTrue($logs->hasWarningThatContains('api_token cannot be decrypted'), 'the log says which');
    }
}
