<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Core\Security\Encrypter;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\DescribedForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * The payment methods as the API answers them, for the screens that work on one: the list in checkout order, one of
 * it, the gateway drivers described, and a method saved whole again with its cards changed (PUT
 * /payment-methods/{id} takes the whole form — a card method's every card).
 */
final class Methods
{
    /**
     * Every method, in checkout order.
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function all(Admin $admin): array
    {
        return Json::rows($admin->api->get('/payment-methods'), 'methods');
    }

    /**
     * The method `$id`; null for none.
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    public static function find(Admin $admin, int $id): ?array
    {
        foreach (self::all($admin) as $method) {
            if ((int) ($method['id'] ?? 0) === $id) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The gateway drivers installed, as the API describes them.
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function drivers(Admin $admin): array
    {
        return Json::rows($admin->api->get('/payment-methods/drivers'), 'drivers');
    }

    /**
     * The gateway driver `$key` as the API describes it; null for one not installed.
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    public static function driver(Admin $admin, string $key): ?array
    {
        foreach (self::drivers($admin) as $driver) {
            if (($driver['key'] ?? null) === $key) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * A card method saved again as it is, its cards `$cards` (each as the API takes one): the label, switch and bounds
     * kept, the driver's fields as kept — the answer's method.
     *
     * @param array<string, mixed> $method
     * @param list<array<string, mixed>> $cards
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public static function saveCards(Admin $admin, Encrypter $crypt, array $method, array $cards): array
    {
        $driver = self::driver($admin, (string) ($method['driver'] ?? '')) ?? [];
        $form = new DescribedForm(Json::rows($driver, 'fields'), $crypt);
        $config = Json::part($method, 'config');
        $body = $form->payload($form->draft($config)) + ['cards' => $cards];
        $saved = $admin->api->put('/payment-methods/' . (int) ($method['id'] ?? 0), $body)['method'] ?? [];

        return is_array($saved) ? $saved : [];
    }

    /**
     * A method's cards as the API takes them back: each kept by its id, in their order.
     *
     * @param array<string, mixed> $method
     * @return list<array<string, mixed>>
     */
    public static function cards(array $method): array
    {
        return array_values(array_map(static fn(array $card): array => [
            'id' => (int) ($card['id'] ?? 0),
            'number' => (string) ($card['number'] ?? ''),
            'holder' => (string) ($card['holder'] ?? ''),
            'bank' => (string) ($card['bank'] ?? ''),
            'enabled' => Json::on($card, 'enabled'),
            'daily_cap' => is_scalar($card['daily_cap'] ?? null) ? (string) (int) round((float) $card['daily_cap']) : '',
        ], array_filter((array) ($method['cards'] ?? []), is_array(...))));
    }
}
