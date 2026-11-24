<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Handlers\CustomEmojiHandler;
use App\Modules\Telegram\Messages;

/**
 * «✨ ایموجی‌های پرمیوم» (the panel's picker of them): the premium emoji the bot knows, latest first — drawn as themselves
 * where the bot may send them —, each taken off the list at a tap, asked first (texts that use it keep it), the page
 * kept; whether Telegram last stripped them from the bot's messages; and «✨ افزودن ایموجی تازه», the bot's own /emoji
 * (CustomEmojiHandler): the message with them sent here — in the bot of the shop on screen alone, as each bot keeps its
 * own —; and the way to the plain emoji the texts draw premium with them (PremiumTextsScreen).
 *
 * Actions: `del` (emoji id, page: asks), `deld` (emoji id, page), `add` (waits for the message), `page` (n).
 */
final class PremiumEmojisScreen extends BaseScreen
{
    public const ID = 'txe';

    public const TITLE = '✨ ایموجی‌های پرمیوم';

    /** The bot's own /emoji from here: the message with new ones sent to this chat (`add`). */
    public const ADD = '✨ افزودن ایموجی تازه';

    /** Said in place of ADD in another shop's bot (here()). */
    public const OWN_BOT = '<i>ایموجی تازه را از ربات همین فروشگاه اضافه کنید: هر ربات ایموجی‌های خودش را دارد.</i>';

    private const PER_PAGE = 24;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $answer = $admin->api->get('/bot/custom-emojis');
        $emojis = self::emojis($answer);
        $status = is_array($answer['status'] ?? null) ? $answer['status'] : null;
        [$shown, $page, $pages] = View::slice($emojis, self::page($args), self::PER_PAGE);

        $view = View::make(self::TITLE, ...[...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)])
            ->total(count($emojis), 'ایموجی');
        if (($status['ok'] ?? null) === false) {
            $view->line()->line('⚠️ آخرین بار تلگرام ایموجی‌های پرمیوم را از پیام ربات برداشت. ' . PremiumTextsScreen::WHY);
        }
        $view->line();
        if ($emojis === []) {
            $view->line('هنوز ایموجی پرمیومی ذخیره نشده است.');
        } else {
            $view->line(implode(' ', array_map(static fn(array $emoji): string => '<tg-emoji emoji-id="' . $emoji['id'] . '">' . Fmt::e((string) ($emoji['emoji'] ?? '')) . '</tg-emoji>', $shown)))
                ->note('برای حذف یکی از فهرست، دکمه‌اش را بزنید؛ متن‌هایی که از آن استفاده کرده‌اند آن را نگه می‌دارند.');
        }
        $view->grid(4, ...array_map(static fn(array $emoji): Button => Button::to('🗑 ' . (string) ($emoji['emoji'] ?? ''), self::ID, 'del', (string) $emoji['id'], $page), $shown));
        $view->pager($page, $pages, self::ID, 'page');
        if (self::here($admin)) {
            $view->row(Button::to(self::ADD, self::ID, 'add')->success());
        } else {
            $view->line()->line(self::OWN_BOT);
        }
        $view->row(Button::to(PremiumTextsScreen::TITLE, PremiumTextsScreen::ID));

        return $view->help('ایموجی‌هایی که ربات می‌شناسد. در «متن‌های ربات» و «کیبوردها» ایموجی پرمیوم را همان‌طور که می‌خواهید بفرستید؛ این‌جا فهرست آن‌هاست.')->back(TextsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = preg_match('/^\d{1,20}$/', $args[0] ?? '') === 1 ? (string) $args[0] : '';
        $page = (string) self::page($args, 1);
        switch ($action) {
            case 'del':
                $emoji = null;
                foreach (self::emojis($admin->api->get('/bot/custom-emojis')) as $row) {
                    $emoji = (string) $row['id'] === $id ? $row : $emoji;
                }
                if ($emoji === null) {
                    return $this->show($admin, [$page]);
                }

                return $admin->confirm(
                    'این ایموجی از فهرست حذف شود؟',
                    Button::to(Words::yes('حذف شود', '🗑'), self::ID, 'deld', $id, $page),
                    Button::to(Words::CANCEL, self::ID, 'page', $page),
                    '<tg-emoji emoji-id="' . $id . '">' . Fmt::e((string) ($emoji['emoji'] ?? '')) . '</tg-emoji> از فهرست ایموجی‌های پنل برداشته می‌شود. متن‌هایی که از آن استفاده کرده‌اند آن را نگه می‌دارند؛ برای برگرداندنش، پیامی با همین ایموجی را دوباره بفرستید.',
                );
            case 'deld':
                if ($id !== '') {
                    $admin->api->delete('/bot/custom-emojis/' . $id);
                    $admin->notice('🗑 از فهرست حذف شد');
                }

                return $this->show($admin, [$page]);
            case 'add':
                if (!self::here($admin)) {
                    return $this->show($admin, []);
                }
                CustomEmojiHandler::await($admin->ctx, (int) $admin->ctx->update->messageId(), Nav::to(self::ID));

                return View::make(self::ADD, ...[...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)])
                    ->line(Messages::EMOJI_ASK)
                    ->row(Button::to(Words::CANCEL, self::ID));
            case 'page':
                return $this->show($admin, [(string) self::page($args)]);
        }

        return $this->show($admin, []);
    }

    /**
     * The emoji GET /bot/custom-emojis lists, latest first.
     *
     * @param array<string, mixed> $answer
     * @return list<array<string, mixed>>
     */
    private static function emojis(array $answer): array
    {
        return array_values(array_filter((array) ($answer['emojis'] ?? []), static fn(mixed $emoji): bool => is_array($emoji) && preg_match('/^\d{1,20}$/', (string) ($emoji['id'] ?? '')) === 1));
    }

    /**
     * Whether the shop on screen is the bot's this chat is in: an emoji sent here is kept for this bot alone, and the
     * premium emoji check (PremiumTextsScreen) goes from this bot to this chat.
     */
    public static function here(Admin $admin): bool
    {
        return $admin->seat->shop()->id === CurrentBot::get()->id;
    }
}
