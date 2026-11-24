<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Emoji\EmojiMap;
use App\Modules\Telegram\Emoji\PremiumCheck;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Texts\BotTexts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «ایموجی پرمیوم متن‌ها» (Telegram\Emoji\EmojiMap): the plain emoji the shop's texts draw premium, the switch — turned on
 * only once the bot was found able to send premium emoji —, and the check that finds it out (Emoji\PremiumCheck), sent by
 * the bot of the shop on screen to a chat of its own.
 */
final class PremiumTextsController extends ApiController
{
    public const NOWHERE = 'ربات جایی برای فرستادن پیام آزمایشی ندارد: حساب تلگرام خود را در «تنظیمات پنل › ورود به پنل» به پنل وصل کنید، یا /emoji را در ربات بفرستید.';
    public const NOWHERE_AGENT = 'ربات حساب تلگرام شما را نمی‌شناسد: یک بار ربات خودتان را در تلگرام باز کنید و /start بزنید، یا /emoji را در آن بفرستید.';
    public const OTHER_BOT = 'این بررسی را ربات همین فروشگاه انجام می‌دهد و آن ربات به حساب شما پیام نمی‌دهد: نماینده آن را از پنل خودش انجام می‌دهد، یا /emoji را در ربات خودش می‌فرستد.';
    public const NOT_SENT = 'ربات نتوانست پیام آزمایشی را بفرستد: یک بار ربات را در تلگرام باز کنید و /start بزنید، یا /emoji را در ربات بفرستید.';

    public function __construct(
        private readonly EmojiMap $map,
        private readonly BotTexts $texts,
        private readonly PremiumEmojiStatus $status,
        private readonly PremiumCheck $check,
        private readonly OwnerLinks $links,
    ) {}

    /** GET /bot/premium-texts */
    public function show(Request $request, Response $response): Response
    {
        return $this->present($response);
    }

    /** PUT /bot/premium-texts — {enabled, items: [{emoji, emoji_id}]} */
    public function update(Request $request, Response $response): Response
    {
        $this->map->save($this->input($request));

        return $this->present($response);
    }

    /**
     * POST /bot/premium-texts/check — the bot sends a premium emoji and reads whether Telegram kept it: to the admin's own
     * chat when they work from the bot (`?chat=`, honoured from there alone), else to the owner's Telegram accounts linked
     * to the panel (the main shop) or to the agent (their own bot's shop).
     */
    public function check(Request $request, Response $response): Response
    {
        $chats = $this->chats(Principal::of($request), $request->getQueryParams());
        $sample = $this->check->sample() ?? throw ValidationException::on('check', PremiumCheck::NOTHING_TO_TEST);
        foreach ($chats as $chat) {
            if ($this->check->probe($chat, $sample) !== null) {
                return $this->present($response);
            }
        }

        throw ValidationException::on('check', self::NOT_SENT);
    }

    /**
     * Where the check may go, in the order tried.
     *
     * @param array<string, mixed> $query
     * @return list<int>
     * @throws ValidationException when nowhere
     */
    private function chats(Principal $principal, array $query): array
    {
        $chat = $query['chat'] ?? null;
        if ($principal->inBot && is_string($chat) && preg_match('/^-?\d{1,20}$/D', $chat) === 1) {
            return [(int) $chat];
        }
        if ($principal->kind === PrincipalKind::Agent) {
            $agent = $principal->shop->agent;

            return $agent?->telegram_id !== null ? [(int) $agent->telegram_id] : throw ValidationException::on('check', self::NOWHERE_AGENT);
        }
        if (!$principal->shop->isMain()) {
            throw ValidationException::on('check', self::OTHER_BOT);
        }

        return $this->links->ids() ?: throw ValidationException::on('check', self::NOWHERE);
    }

    private function present(Response $response): Response
    {
        $stored = $this->map->stored();
        $uses = EmojiMap::uses(array_column($stored['items'], 'emoji'), $this->texts->wordings());

        return $this->json($response, ['premium_texts' => [
            'enabled' => $stored['enabled'],
            'items' => array_map(static fn(array $item): array => $item + ['texts' => $uses[EmojiMap::key($item['emoji'])] ?? 0], $stored['items']),
            'status' => $this->status->current(),
            'can_enable' => $this->map->canEnable(),
            'max' => EmojiMap::MAX,
        ]]);
    }
}
