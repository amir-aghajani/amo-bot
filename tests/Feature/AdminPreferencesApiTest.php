<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Admin\Services\PanelPreferences;
use App\Modules\Bots\Models\Bot;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * The settings search's favorites, kept on the server so they follow the panel's user to every device: the owner's are
 * one list whatever shop is open, an agent's their own; the list is kept in the user's order, an id twice once, and
 * anything but the panel's ids is refused.
 */
final class AdminPreferencesApiTest extends HttpTestCase
{
    /** A setting with no DOM id of its own is named by its label: Persian in the id. */
    private const FAVORITES = ['section:/bot-settings/wallet', 'setting:/settings/telegram#telegram_token', 'page:/plans', 'setting:/bot-settings/qr#پس-زمینه-کد-qr'];

    public function testTheOwnersFavoritesAreOneListWhateverShopIsOpen(): void
    {
        $shop = $this->agentBot();
        $this->loginAsAdmin();
        self::assertSame([], $this->favorites($this->get('/api/admin/preferences')), 'none at first');

        $saved = $this->putJson('/api/admin/preferences/favorites', ['favorites' => [...self::FAVORITES, 'page:/plans']]);
        self::assertSame(self::FAVORITES, $this->favorites($saved), 'in the order given, an id twice kept once');

        $this->openShop($shop);
        self::assertSame(self::FAVORITES, $this->favorites($this->get('/api/admin/preferences')), "the same list on an agent's shop's tab");
        $this->putJson('/api/admin/preferences/favorites', ['favorites' => ['page:/orders']]);
        $this->openShop(Bot::query()->findOrFail(Bot::MAIN));
        self::assertSame(['page:/orders'], $this->favorites($this->get('/api/admin/preferences')), 'saved from any tab, one list');
    }

    public function testAnAgentsFavoritesAreTheirOwn(): void
    {
        $shop = $this->agentBot();
        $this->loginAsAdmin();
        $this->putJson('/api/admin/preferences/favorites', ['favorites' => self::FAVORITES]);

        $this->loginAsAgent($shop);
        self::assertSame([], $this->favorites($this->get('/api/agent/preferences')), "none of the owner's");
        $this->putJson('/api/agent/preferences/favorites', ['favorites' => ['section:/settings/backups']]);
        self::assertSame(['section:/settings/backups'], $this->favorites($this->get('/api/agent/preferences')));

        $this->loginAsAdmin();
        self::assertSame(self::FAVORITES, $this->favorites($this->get('/api/admin/preferences')), "the owner's as they were");
    }

    public function testAnEmptyListForgetsThemAndAnythingButTheirIdsIsRefused(): void
    {
        $this->loginAsAdmin();
        $this->putJson('/api/admin/preferences/favorites', ['favorites' => self::FAVORITES]);
        self::assertSame([], $this->favorites($this->putJson('/api/admin/preferences/favorites', ['favorites' => []])));

        foreach ([['favorites' => 'page:/plans'], ['favorites' => ['plans']], ['favorites' => ['page:/a b']], ['favorites' => [7]], ['favorites' => ['x' => 'page:/plans']], []] as $body) {
            $refused = $this->unchecked()->putJson('/api/admin/preferences/favorites', $body);
            self::assertSame(422, $refused->getStatusCode(), (string) json_encode($body));
            self::assertSame(['favorites'], array_keys($this->decode($refused)['errors']));
        }

        $many = array_map(static fn(int $i): string => "page:/p{$i}", range(1, PanelPreferences::MOST + 1));
        self::assertSame(422, $this->unchecked()->putJson('/api/admin/preferences/favorites', ['favorites' => $many])->getStatusCode(), 'more than a list keeps');
        self::assertSame([], $this->favorites($this->get('/api/admin/preferences')), 'nothing refused was kept');
    }

    /** @return list<string> */
    private function favorites(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['preferences']['favorites'];
    }
}
