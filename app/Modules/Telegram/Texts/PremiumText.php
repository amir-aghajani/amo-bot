<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Providers\Models\Server;

/**
 * Words of the shop's own that may carry a premium emoji — a plan's, a category's or a server's name, its leading emoji
 * the premium one the shop chose for it (`emoji_id`; a server's in its `meta`) — as a bot text's value: BotTexts fills it in by the text's kind, escaped with
 * its premium emoji kept in a text Telegram reads as HTML, as it is in a button (the API client sends a label's leading
 * premium emoji as the button's icon) or a popup (which shows it plain). Its `text` goes into a button's label as it is.
 *
 * The name itself stays plain everywhere — the panels, the mini app, the website, the reports —, its leading emoji the
 * plain one; only the bot draws it premium.
 */
final readonly class PremiumText implements \Stringable
{
    public const ID_REFUSED = 'شناسه ایموجی پرمیوم یک عدد است؛ آن را از «ایموجی پرمیوم» انتخاب کنید.';
    public const NO_EMOJI = 'ایموجی پرمیوم جای ایموجی اول نام می‌نشیند؛ نام را با یک ایموجی شروع کنید، یا ایموجی پرمیوم را بردارید.';
    public const TAG_IN_NAME = 'ایموجی پرمیوم را در خود نام ننویسید؛ آن را با «ایموجی پرمیوم» کنار نام انتخاب کنید.';

    /** @param string $text Plain words, a premium emoji in them as PlainWording writes one */
    private function __construct(public string $text) {}

    /** A plan's name as the bot writes it. */
    public static function plan(Plan $plan): self
    {
        return self::name($plan->name, $plan->emoji_id);
    }

    /** A category's name as the bot writes it. */
    public static function category(PlanCategory $category): self
    {
        return self::name($category->name, $category->emoji_id);
    }

    /** A server's (a location's) name as the bot writes it. */
    public static function server(Server $server): self
    {
        return self::name($server->name, $server->emojiId());
    }

    /** A name with its leading emoji as the premium emoji `$emojiId` names, when it has both; else as it is. */
    public static function name(string $name, ?string $emojiId): self
    {
        $leading = self::leadingEmoji($name);
        if ($emojiId === null || $leading === null) {
            return new self($name);
        }

        return new self('<tg-emoji emoji-id="' . $emojiId . '">' . $leading . '</tg-emoji>' . substr($name, strlen($leading)));
    }

    /** The emoji a name starts with — one as a reader sees it, its modifiers and joiners with it —, or null. */
    public static function leadingEmoji(string $name): ?string
    {
        return preg_match('/^\X/u', $name, $first) === 1 && TelegramHtml::isEmoji($first[0]) ? $first[0] : null;
    }

    /**
     * The premium emoji of a name (a plan's, a category's) as the request gives it in `emoji_id` — its id, or blank or
     * null for none; left out, the one it had while the name still starts with an emoji —, what is wrong put in
     * `$errors` (under `name` or `emoji_id`). A name never carries one's tag itself: the shop's other doors show it plain.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    public static function nameEmoji(array $input, string $name, ?string $had, array &$errors): ?string
    {
        if (stripos($name, '<tg-emoji') !== false) {
            $errors['name'][] = self::TAG_IN_NAME;

            return null;
        }
        $leading = self::leadingEmoji($name);
        if (!array_key_exists('emoji_id', $input)) {
            return $leading === null ? null : $had;
        }

        $id = $input['emoji_id'];
        if ($id === null || $id === '') {
            return null;
        }
        if (!is_string($id) || preg_match('/^\d{1,20}$/D', $id) !== 1) {
            $errors['emoji_id'][] = self::ID_REFUSED;

            return null;
        }
        if ($leading === null) {
            $errors['name'][] = self::NO_EMOJI;

            return null;
        }

        return $id;
    }

    /** The words as they are, a premium emoji's tag and all: what goes into a button's label. */
    public function __toString(): string
    {
        return $this->text;
    }
}
