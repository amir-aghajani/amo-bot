<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\HttpTestCase;

/**
 * The sign-in forms take what anyone on the internet sends: a field that is not text is an empty one — a 422 like any
 * missing field —, never a PHP warning in the host's log for every such request.
 */
final class SignInInputTest extends HttpTestCase
{
    public function testAPasswordThatIsNoTextIsAMissingOne(): void
    {
        foreach ([['x'], ['nested' => ['y']], null, true] as $password) {
            $response = $this->unchecked()->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => $password]);

            self::assertSame(422, $response->getStatusCode(), (string) json_encode($password));
            self::assertArrayHasKey('password', $this->decode($response)['errors']);
        }
    }

    public function testACodeOrAUsernameThatIsNoTextOpensNothing(): void
    {
        self::assertSame(401, $this->unchecked()->postJson('/api/agent/auth/link', ['code' => ['x']])->getStatusCode());
        self::assertSame(422, $this->unchecked()->postJson('/api/admin/auth/login', ['username' => ['root'], 'password' => self::ADMIN_PASSWORD])->getStatusCode());
    }
}
