<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Marzban;

use App\Core\Security\Encrypter;
use App\Modules\Providers\Drivers\Marzban\MarzbanClient;
use App\Modules\Providers\Drivers\Marzban\MarzbanTokens;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * Marzban's tokens kept across requests: a client of another request for the same saved server and credentials signs
 * in no more; a changed password, address or way there signs in afresh; a token the panel refuses is dropped; a token
 * goes shortly before the end its JWT names (else Marzban's default day); and what is written gives nothing away.
 */
final class MarzbanTokensTest extends TestCase
{
    private const BASE = 'https://mz.test:8000';
    private const SERVER = 7;

    private FakePanel $panel;

    private string $directory;

    private MarzbanTokens $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panel = new FakePanel();
        $this->directory = $this->scratchDir() . '/panel-tokens';
        $this->tokens = new MarzbanTokens($this->service(Encrypter::class), $this->directory);
    }

    public function testAClientOfAnotherRequestUsesTheKeptTokenWithoutSigningIn(): void
    {
        $this->panel->raw(self::token('jwt-1'), self::json(200, []), self::json(200, ['again' => true]));

        $this->client()->get('/api/system');
        self::assertSame(['again' => true], $this->client()->get('/api/system'), 'the next request: a client of its own');

        self::assertSame(['POST /api/admin/token', 'GET /api/system', 'GET /api/system'], $this->panel->calls(), 'one sign-in for both: one report to the panel\'s admins');
        self::assertSame('Bearer jwt-1', $this->panel->request(2)->getHeaderLine('Authorization'));
    }

    public function testAChangedPasswordAddressWayThereOrServerSignsInAfresh(): void
    {
        $this->panel->raw(self::token('jwt-1'), self::json(200, []));
        $this->client()->get('/api/system');

        foreach ([
            'another password' => $this->client(password: 'new pass'),
            'another username' => $this->client(username: 'other'),
            'another address' => $this->client(base: 'https://mz2.test:8000'),
            'another path on the same host' => $this->client(base: self::BASE . '/other'),
            'through a proxy' => $this->client(proxy: PanelConnection::proxy('proxy.test', 1080)),
            'its certificate no longer checked' => $this->client(verifyTls: false),
            'another server' => $this->client(server: self::SERVER + 1),
        ] as $case => $client) {
            $this->panel->reset();
            $this->panel->raw(self::token('jwt-2'), self::json(200, []));

            $client->get('/api/system');

            $calls = array_map(static fn(string $call): string => str_replace(' /other/', ' /', $call), $this->panel->calls());
            self::assertSame(['POST /api/admin/token', 'GET /api/system'], $calls, "{$case}: never the token issued for the old");
        }
    }

    public function testATokenThePanelRefusesIsDroppedAndTheClientSignsInOnce(): void
    {
        $this->panel->raw(self::token('old'), self::json(200, []));
        $this->client()->get('/api/system');

        // Another request, a day later: the kept token is refused, dropped, and the fresh one kept instead.
        $this->panel->reset();
        $this->panel->raw(self::json(401, ['detail' => 'Could not validate credentials']), self::token('new'), self::json(200, ['fresh' => true]));
        self::assertSame(['fresh' => true], $this->client()->get('/api/system'));
        self::assertSame(['GET /api/system', 'POST /api/admin/token', 'GET /api/system'], $this->panel->calls(), 'one sign-in, no loop');
        self::assertSame(['Bearer old', 'Bearer new'], [$this->panel->request(0)->getHeaderLine('Authorization'), $this->panel->request(2)->getHeaderLine('Authorization')]);

        $this->panel->reset();
        $this->panel->raw(self::json(200, []));
        $this->client()->get('/api/system');
        self::assertSame('Bearer new', $this->panel->request(0)->getHeaderLine('Authorization'), 'the fresh token serves the next request');

        // A fresh token refused too: nothing is kept, and the next request signs in.
        $this->panel->reset();
        $this->panel->raw(self::json(401, ['detail' => 'Could not validate credentials']), self::token('newer'), self::json(401, ['detail' => 'Could not validate credentials']));
        try {
            $this->client()->get('/api/system');
            self::fail('the panel refused the fresh token');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Session, $e->failure);
        }
        self::assertSame([], glob($this->directory . '/*') ?: [], 'nothing kept');
    }

    public function testARefusedLoginLeavesNothingKept(): void
    {
        $this->panel->raw(self::token('old'), self::json(200, []));
        $this->client()->get('/api/system');

        // The admin's password changed on the panel: the kept token is refused, and so is the login.
        $this->panel->raw(self::json(401, ['detail' => 'Could not validate credentials']), self::json(401, ['detail' => 'Incorrect username or password']));
        try {
            $this->client()->get('/api/system');
            self::fail('the login was refused');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Login, $e->failure);
        }

        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testWhatIsWrittenGivesNothingAway(): void
    {
        $jwt = self::jwt(['sub' => 'admin', 'access' => 'sudo', 'exp' => now()->getTimestamp() + 86_400]);
        $this->panel->raw(self::token($jwt), self::json(200, []));

        $this->client()->get('/api/system');

        $files = glob($this->directory . '/*') ?: [];
        self::assertCount(1, $files);
        $name = basename($files[0]);
        $content = (string) file_get_contents($files[0]);
        foreach ([$jwt, 'S3cret pass', 'admin', 'mz.test'] as $secret) {
            self::assertStringNotContainsString($secret, $name);
            self::assertStringNotContainsString($secret, $content);
        }
        self::assertNull(json_decode($content, true), 'no JSON in the clear');
        self::assertSame($jwt, json_decode($this->service(Encrypter::class)->decrypt($content), true)['token'] ?? null, 'encrypted with APP_KEY');
    }

    public function testATokenGoesShortlyBeforeTheEndItsJwtNamesElseBeforeMarzbansDefaultDay(): void
    {
        Carbon::setTestNow('2026-11-13 12:00:00');
        $start = now()->getTimestamp();

        $this->tokens->keep('day', self::jwt(['sub' => 'admin', 'iat' => $start, 'exp' => $start + 86_400]));
        $this->tokens->keep('opaque', 'an-opaque-token');
        $this->tokens->keep('past', self::jwt(['sub' => 'admin', 'iat' => $start - 600, 'exp' => $start - 1]));

        self::assertNull($this->tokens->find('past'), 'one past its end is not kept');
        Carbon::setTestNow(Carbon::createFromTimestamp($start + 86_400 - 601));
        self::assertNotNull($this->tokens->find('day'));
        self::assertSame('an-opaque-token', $this->tokens->find('opaque'));
        Carbon::setTestNow(Carbon::createFromTimestamp($start + 86_400 - 600));
        self::assertNull($this->tokens->find('day'), 'ten minutes before the end its JWT names');
        self::assertNull($this->tokens->find('opaque'), 'one naming no end: ten minutes before Marzban\'s default day');
        self::assertSame([], glob($this->directory . '/*') ?: [], 'a token past its time is deleted');
    }

    /** A panel set to tokens of a few minutes still has them kept: the margin is a tenth of a token's life when that is shorter. */
    public function testAShortLivedTokenIsKeptTooWithAMarginOfATenthOfItsLife(): void
    {
        Carbon::setTestNow('2026-11-13 12:00:00');
        $start = now()->getTimestamp();

        $this->tokens->keep('five minutes', self::jwt(['sub' => 'admin', 'iat' => $start, 'exp' => $start + 300]));
        $this->tokens->keep('an hour, no iat', self::jwt(['sub' => 'admin', 'exp' => $start + 3600]));

        Carbon::setTestNow(Carbon::createFromTimestamp($start + 269));
        self::assertNotNull($this->tokens->find('five minutes'));
        Carbon::setTestNow(Carbon::createFromTimestamp($start + 270));
        self::assertNull($this->tokens->find('five minutes'), 'thirty seconds before its end');

        Carbon::setTestNow(Carbon::createFromTimestamp($start + 3239));
        self::assertNotNull($this->tokens->find('an hour, no iat'));
        Carbon::setTestNow(Carbon::createFromTimestamp($start + 3240));
        self::assertNull($this->tokens->find('an hour, no iat'), 'its life counted from when it was kept');
    }

    public function testOnlyTheTokenTheClientSentIsDropped(): void
    {
        $this->tokens->keep('panel', 'refused-token');
        $this->tokens->forget('panel', 'refused-token');
        self::assertNull($this->tokens->find('panel'));

        // Another request signed in meanwhile and kept a fresh token: a client refused with the old one leaves it.
        $this->tokens->keep('panel', 'fresh-token');
        $this->tokens->forget('panel', 'refused-token');
        self::assertSame('fresh-token', $this->tokens->find('panel'));
    }

    public function testAClientDropsNoTokenAnotherRequestKeptMeanwhile(): void
    {
        $refused = static fn(): Response => self::json(401, ['detail' => 'Could not validate credentials']);
        $mine = $this->client();
        $this->panel->raw(self::token('old'), self::json(200, []));
        $mine->get('/api/system');

        // Another request finds "old" refused, and keeps a fresh token of its own.
        $this->panel->raw($refused(), self::token('theirs'), self::json(200, []));
        $this->client()->get('/api/system');

        // This client, still holding "old", is refused too, and its own sign-in fails: the other request's token stays.
        $this->panel->raw($refused(), self::json(401, ['detail' => 'Incorrect username or password']));
        try {
            $mine->get('/api/system');
            self::fail('the login was refused');
        } catch (AuthenticationException) {
        }

        $this->panel->reset();
        $this->panel->raw(self::json(200, []));
        $this->client()->get('/api/system');
        self::assertSame(['GET /api/system'], $this->panel->calls());
        self::assertSame('Bearer theirs', $this->panel->request(0)->getHeaderLine('Authorization'));
    }

    public function testATokenPastItsTimeIsNotSent(): void
    {
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->panel->raw(self::token(self::jwt(['sub' => 'admin', 'exp' => now()->getTimestamp() + 3600])), self::json(200, []));
        $this->client()->get('/api/system');

        Carbon::setTestNow('2026-11-13 13:00:00');
        $this->panel->reset();
        $this->panel->raw(self::token('jwt-2'), self::json(200, []));
        $this->client()->get('/api/system');

        self::assertSame(['POST /api/admin/token', 'GET /api/system'], $this->panel->calls());
    }

    public function testWithoutASavedServerAKeyOrAFolderNothingIsKeptAndNothingFails(): void
    {
        $this->panel->raw(self::token('jwt-1'), self::json(200, []));
        $this->client(server: null)->get('/api/system');
        self::assertSame([], glob($this->directory . '/*') ?: [], 'a server being tried before it is saved keeps its token for the client\'s life');

        // No APP_KEY yet, or a folder that cannot be made (a file stands where it would be): the shop signs in as it would without.
        $blocked = $this->scratchDir();
        touch($blocked . '/file');
        foreach ([new MarzbanTokens(new Encrypter(''), $this->directory), new MarzbanTokens($this->service(Encrypter::class), $blocked . '/file/tokens')] as $tokens) {
            $tokens->keep('x', 'jwt');
            self::assertNull($tokens->find('x'));
            $tokens->forget('x', 'jwt');
        }
    }

    private function client(string $password = 'S3cret pass', string $username = 'admin', string $base = self::BASE, ?string $proxy = null, bool $verifyTls = true, ?int $server = self::SERVER): MarzbanClient
    {
        return new MarzbanClient(
            new PanelHttp($this->panel->client(), new NullLogger()),
            new PanelConnection($base, verifyTls: $verifyTls, proxy: $proxy),
            new PanelCredentials(AuthMode::Password, username: $username, password: $password),
            $this->tokens,
            $server,
        );
    }

    /** @param array<string, mixed> $claims */
    private static function jwt(array $claims): string
    {
        $part = static fn(array $json): string => rtrim(strtr(base64_encode((string) json_encode($json)), '+/', '-_'), '=');

        return $part(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $part($claims) . '.c2lnbmF0dXJl';
    }

    private static function token(string $jwt): Response
    {
        return self::json(200, ['access_token' => $jwt, 'token_type' => 'bearer']);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
