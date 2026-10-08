<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Telegram\Keyboard\KeyboardLayout;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Keyboard\MainMenu;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * The bot's keyboards for the layout editor: each keyboard's rows, the actions a button can stand
 * for and Telegram's button styles.
 */
final class KeyboardsController extends ApiController
{
    public function __construct(private readonly KeyboardLayouts $layouts) {}

    /** GET /keyboards */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'keyboards' => array_map($this->layouts->present(...), KeyboardLayouts::names()),
            'actions' => MainMenu::actions(),
            'styles' => KeyboardLayout::STYLES,
            'limits' => ['rows' => KeyboardLayouts::MAX_ROWS, 'per_row' => KeyboardLayouts::MAX_PER_ROW, 'label' => KeyboardLayouts::LABEL_MAX],
        ]);
    }

    /**
     * PUT /keyboards/{name} — {type, rows: [[{action, label, style, icon}]]}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $name = self::keyboard($request, $args);
        $this->layouts->save($name, $this->input($request));

        return $this->json($response, ['keyboard' => $this->layouts->present($name)]);
    }

    /**
     * POST /keyboards/{name}/reset — back to the built-in layout.
     *
     * @param array<string, string> $args
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $name = self::keyboard($request, $args);
        $this->layouts->reset($name);

        return $this->json($response, ['keyboard' => $this->layouts->present($name)]);
    }

    /**
     * The keyboard the route names; one the bot does not have is a 404.
     *
     * @param array<string, string> $args
     */
    private static function keyboard(Request $request, array $args): string
    {
        $name = (string) $args['name'];

        return in_array($name, KeyboardLayouts::names(), true) ? $name : throw new HttpNotFoundException($request);
    }
}
