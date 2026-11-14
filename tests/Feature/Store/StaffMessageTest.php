<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * «ارسال پیام» from the website's admin side (`POST /admin/users/{id}/message`): to a customer, as the panel sends it;
 * never to an admin's account — the admin's own included — nor an agent's, as no other change from the website is.
 */
final class StaffMessageTest extends HttpTestCase
{
    private Website $website;

    /** The shop's admin, signed in on its website. */
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->sara = $this->customer(['telegram_id' => 7001, 'username' => 'sara', 'first_name' => 'Sara']);
        $this->loginAsStaff($this->website, $this->sara);
    }

    public function testNoAdminWritesToAnAdminsOrAnAgentsAccountFromTheWebsite(): void
    {
        $reza = $this->admin(['telegram_id' => 7003, 'username' => 'reza']);
        $agent = $this->agent(overrides: ['telegram_id' => 7004, 'username' => 'agent']);

        $this->assertRefused(ActorRefusedException::ADMIN_ACCOUNT, $this->message($reza));
        $this->assertRefused(ActorRefusedException::ADMIN_ACCOUNT, $this->message($this->sara));
        $this->assertRefused(ActorRefusedException::AGENT_ACCOUNT, $this->message($agent));
        self::assertSame([], $this->telegram()->sentTo(7003));
        self::assertSame([], $this->telegram()->sentTo(7001));
        self::assertSame([], $this->telegram()->sentTo(7004));
        self::assertSame(0, Notification::query()->count(), 'nothing kept for their websites either');

        $ali = $this->customer(['telegram_id' => 7002, 'username' => 'ali']);
        self::assertSame(['delivery' => 'told'], $this->decode($this->message($ali)), 'a customer is written to');
    }

    private function message(User $user): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, "/admin/users/{$user->id}/message"), ['text' => 'سلام']);
    }

    private function assertRefused(string $message, ResponseInterface $response): void
    {
        self::assertSame([403, $message], [$response->getStatusCode(), $this->decode($response)['message'] ?? null], (string) $response->getBody());
    }
}
