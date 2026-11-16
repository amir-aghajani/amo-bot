<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Texts\PlainWording;
use App\Modules\Telegram\Texts\TelegramHtml;

/**
 * One bot text (the panel's dialog of it): what it is for, its kind, its wording as the panel keeps it (Telegram HTML or
 * plain words, a page at a time — tapped, Telegram copies it), how much of its limit it takes, and its variables; then
 * «✏️ ویرایش» — the new wording sent as a message, its formatting and premium emoji kept as the panel keeps them —,
 * «🧾 ویرایش با کد» for a wording that is HTML (sent as code), «👁 پیش‌نمایش» (sent with the variables' samples, as the
 * customer would get it: a popup's as a popup), the shop's default wording, and «↩️ پیش‌فرض» — the default back —, asked
 * first. A wording the panel refuses is said over the question, which still waits.
 *
 * Opened with: the text's key. Actions (the key first): `e`, `h` (ask), `empty` (a part emptied), `pv` (page), `pvd`
 * (the previewed button tapped: the window it was asked from), `vars`, `pg` (page), `df` (page: the default), `rs`
 * (asks), `rsd`.
 */
final class TextScreen extends BaseScreen
{
    public const ID = 'txv';

    /** The most of a wording one page of the screen shows, escaped. */
    private const WORDING_MOST = 1500;

    /** A `%name%` the bot fills in. */
    private const VARIABLE = '/%([A-Za-z_][A-Za-z0-9_]*)%/';

    /** What each kind takes, said where the new wording is asked for. */
    private const HINTS = [
        'message' => 'پیام را همان‌طور که می‌خواهید مشتری ببیند بفرستید: پررنگ، کج، زیرخط، خط‌خورده، کد، مخفی، نقل‌قول، لینک و ایموجی پرمیوم همان‌طور ذخیره می‌شود.',
        'caption' => "متن زیر عکس کد \u{2068}QR\u{2069} لینک؛ قالب‌بندی مثل پیام. تلگرام زیر عکس متن کوتاه‌تری از یک پیام جا می‌دهد؛ اگر متن با مقدارهای واقعی (مثلا یک لینک بلند) از آن بیشتر شود، بدون عکس فرستاده می‌شود.",
        'popup' => 'اعلان کوتاه تلگرام روی دکمه‌ای که مشتری زده است؛ قالب‌بندی ندارد و همان‌طور که نوشته شود نشان داده می‌شود. تلگرام در اعلان ایموجی پرمیوم نشان نمی‌دهد و ایموجی معمولی آن دیده می‌شود؛ جاهایی که همین متن به صورت پیام فرستاده می‌شود، خود ایموجی پرمیوم.',
        'button' => "متن روی دکمه؛ یک خط و بدون قالب‌بندی. یک ایموجی پرمیوم اول متن، آیکون دکمه می‌شود: تلگرام آن را پیش از متن دکمه نشان می‌دهد (فقط وقتی حسابی که ربات را در \u{2068}@BotFather\u{2069} ساخته تلگرام پرمیوم داشته باشد؛ وگرنه ایموجی معمولی آن اول متن دکمه می‌نشیند).",
        'part' => 'این بخش داخل متن دیگری می‌نشیند؛ قالب‌بندی مثل پیام. خط‌های خالی اول متن فعلی (فاصله آن با متن بالایی) نگه داشته می‌شود. اگر متغیر لازمی ندارد، «خالی کردن» را بزنید تا نشان داده نشود.',
    ];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        [$text, $group] = $this->find($admin, $args[0] ?? '');

        return $text === null ? $admin->open(TextsScreen::ID) : $this->view($admin, $text, $group, 1);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $key = $args[0] ?? '';
        [$text, $group] = $this->find($admin, $key);
        if ($text === null) {
            return $admin->open(TextsScreen::ID);
        }
        $html = Json::on($text, 'html');
        switch ($action) {
            case 'e':
                return $this->ask($admin, $text, 'e');
            case 'h':
                return $html ? $this->ask($admin, $text, 'h') : $this->view($admin, $text, $group, 1);
            case 'empty':
                return ($text['kind'] ?? null) === 'part' ? $this->save($admin, $text, '') : $this->view($admin, $text, $group, 1);
            case 'pv':
                $this->preview($admin, $text);

                return $this->view($admin, $text, $group, self::page($args, 1));
            case 'pvd':
                // The previewed button tapped: its message becomes the window, the one the preview was asked from goes.
                $window = self::id($args, 1);
                if ($window > 0 && $window !== $admin->ctx->update->messageId()) {
                    $admin->ctx->deleteMessage($window);
                }

                return $this->view($admin, $text, $group, 1);
            case 'vars':
                return $this->variables($text);
            case 'pg':
                return $this->view($admin, $text, $group, self::page($args, 1));
            case 'df':
                return $this->fallback($text, self::page($args, 1));
            case 'rs':
                return $admin->confirm(
                    'متن پیش‌فرض برگردد؟',
                    Button::to(Words::yes('متن پیش‌فرض برگردد', '↩️'), self::ID, 'rsd', $key),
                    Button::to(Words::CANCEL, self::ID, 'show', $key),
                    '«' . Fmt::e((string) ($text['title'] ?? '')) . '» دوباره با متن پیش‌فرض فروشگاه فرستاده می‌شود. متن فعلی شما کنار گذاشته می‌شود و برنمی‌گردد.',
                    danger: true,
                );
            case 'rsd':
                $text = (array) ($admin->api->post("/bot/texts/{$key}/reset")['text'] ?? []);
                $admin->notice('✅ متن پیش‌فرض دوباره به کار می‌رود');

                return $this->view($admin, $text, $group, 1);
        }

        return $this->view($admin, $text, $group, 1);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        [$text] = $this->find($admin, $args[0] ?? '');
        if ($text === null) {
            return $admin->open(TextsScreen::ID);
        }
        $value = match ($action) {
            'h' => $answer->text(),
            default => Wording::of($answer->message(), Json::on($text, 'html')),
        };
        if (($text['kind'] ?? null) === 'part') {
            // Telegram takes a message's leading line breaks off, typed code's too: the part keeps its own, its spacing.
            $value = substr((string) ($text['value'] ?? ''), 0, strspn((string) ($text['value'] ?? ''), "\n")) . $value;
        }

        return $this->save($admin, $text, $value);
    }

    /**
     * Save `$value` as the text's wording — the panel's check answers it — and show the text as the panel then has it.
     *
     * @param array<string, mixed> $text
     * @throws ApiRefusal
     */
    private function save(Admin $admin, array $text, string $value): View
    {
        $key = (string) ($text['key'] ?? '');
        $saved = (array) ($admin->api->put("/bot/texts/{$key}", ['value' => $value])['text'] ?? []);
        $admin->notice(Json::on($saved, 'customized') ? '✅ متن ذخیره شد؛ ربات از این به بعد همین را می‌فرستد' : '✅ متن پیش‌فرض دوباره به کار می‌رود');
        [, $group] = $this->find($admin, $key);

        return $this->view($admin, $saved, $group, 1);
    }

    /**
     * The text's screen, its wording at `$page`.
     *
     * @param array<string, mixed> $text
     */
    private function view(Admin $admin, array $text, string $group, int $page): View
    {
        $key = (string) ($text['key'] ?? '');
        $kind = (string) ($text['kind'] ?? 'message');
        $value = (string) ($text['value'] ?? '');
        $html = Json::on($text, 'html');
        $variables = Json::rows($text, 'variables');
        $pages = self::pages($value);
        $page = min(count($pages), $page);
        $length = $html ? TelegramHtml::visibleLength($value) : mb_strlen(PlainWording::shown($value));
        $limit = (int) ($text['limit'] ?? 0);

        $view = View::make(Icons::TEXTS . ' ' . (string) ($text['title'] ?? $key), ...[...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE), $group])
            ->line('<i>' . self::tokens((string) ($text['description'] ?? '')) . '</i>')
            ->card([
                'نوع' => Fmt::e(TextsScreen::KINDS[$kind] ?? $kind),
                'وضعیت' => Json::on($text, 'customized') ? '✏️ تغییر یافته' : '📄 متن پیش‌فرض',
                'طول' => ($length > $limit ? '⚠️ ' : '') . Fmt::number($length) . ' از ' . Fmt::number($limit) . ' کاراکتر' . ($html ? '، بدون تگ‌ها' : ''),
                'متغیرها' => $variables === [] ? 'ندارد' : implode('، ', array_map(static fn(array $variable): string => Fmt::ltr('%' . (string) ($variable['name'] ?? '') . '%'), $variables)),
            ]);
        $required = array_filter($variables, static fn(array $variable): bool => Json::on($variable, 'required') && !str_contains($value, '%' . (string) ($variable['name'] ?? '') . '%'));
        foreach ($required as $variable) {
            $view->line('⚠️ ' . Fmt::ltr('%' . (string) ($variable['name'] ?? '') . '%') . ' لازم است.');
        }
        $view->section('📄 متن فعلی' . (count($pages) > 1 ? ' (' . Words::page($page, count($pages)) . ')' : ''))
            ->line($value === '' ? '<i>(خالی)</i>' : '<pre>' . Fmt::e($pages[$page - 1]) . '</pre>');
        $view->row(Button::to(Words::EDIT, self::ID, 'e', $key)->primary(), $html ? Button::to("🧾 ویرایش با کد \u{2068}HTML\u{2069}", self::ID, 'h', $key) : null)
            ->row(Button::to('👁 پیش‌نمایش', self::ID, 'pv', $key, $page), Button::to('🔣 متغیرها' . Fmt::badge(count($variables)), self::ID, 'vars', $key))
            ->row(Button::to('📄 دیدن متن پیش‌فرض', self::ID, 'df', $key), Json::on($text, 'customized') ? Button::to(Words::RESET, self::ID, 'rs', $key) : null)
            ->pager($page, count($pages), self::ID, 'pg', $key);
        $search = (string) ($admin->state(TextsScreen::ID)['search'] ?? '');
        $groupState = $admin->state(TextGroupScreen::ID);

        return $search !== '' || ($text['group'] ?? null) === null
            ? $view->back(TextsScreen::ID)
            : $view->back(TextGroupScreen::ID, 'show', (string) $text['group'], ($groupState['group'] ?? null) === $text['group'] ? (int) ($groupState['page'] ?? 1) : 1);
    }

    /**
     * Ask for the new wording: as a message (`e`) or as its HTML code (`h`, the code now shown to copy — its first page
     * for a long one). Any words are an answer, a menu button's label too.
     *
     * @param array<string, mixed> $text
     */
    private function ask(Admin $admin, array $text, string $how): View
    {
        $key = (string) ($text['key'] ?? '');
        $kind = (string) ($text['kind'] ?? 'message');
        $lines = [$how === 'h'
            ? "کد \u{2068}HTML\u{2069} متن را بفرستید، همان‌طور که در پنل نوشته می‌شود: تگ‌هایی مثل \u{2068}&lt;b&gt;…&lt;/b&gt;\u{2069}؛ برای نوشتن خود نماد \u{2068}&lt;\u{2069} از \u{2068}&amp;lt;\u{2069} استفاده کنید."
            : (self::HINTS[$kind] ?? '')];
        $names = array_map(static fn(array $variable): string => Fmt::ltr('%' . (string) ($variable['name'] ?? '') . '%') . (Json::on($variable, 'required') ? ' (لازم)' : ''), Json::rows($text, 'variables'));
        $lines[] = $names === [] ? 'این متن متغیری ندارد.' : 'متغیرها: ' . implode('، ', $names);
        $value = (string) ($text['value'] ?? '');
        if ($how === 'h' && $value !== '') {
            // Cut at a page's lines and escaped: a long code shows its first part, never a broken tag or entity.
            $pages = self::pages($value);
            $lines[] = '';
            $lines[] = 'کد فعلی' . (count($pages) > 1 ? ' (بخش اول از ' . Fmt::number(count($pages)) . '؛ بقیه در صفحه متن)' : '') . '؛ با زدن رویش کپی می‌شود:';
            $lines[] = '<code>' . Fmt::e($pages[0]) . '</code>';
        }
        $prompt = (new Prompt((string) ($text['title'] ?? $key), self::ID, $how, [$key], implode("\n", $lines), anyText: true))
            ->limits('حداکثر ' . Fmt::plain(Fmt::number((int) ($text['limit'] ?? 0))) . ' کاراکتر');
        if ($kind === 'part' && ($text['value'] ?? '') !== '') {
            $prompt->clearable(self::ID, 'empty', $key);
        }

        return $admin->ask($prompt);
    }

    /**
     * The wording as a customer would get it, the variables' samples in: a message under the screen — a popup's as the
     * popup itself.
     *
     * @param array<string, mixed> $text
     */
    private function preview(Admin $admin, array $text): void
    {
        $html = Json::on($text, 'html');
        $samples = array_column(Json::rows($text, 'variables'), null, 'name');
        $filled = (string) preg_replace_callback(self::VARIABLE, static function (array $token) use ($samples, $html): string {
            $variable = $samples[$token[1]] ?? null;
            if (!is_array($variable)) {
                return $token[0];
            }
            $sample = (string) ($variable['sample'] ?? '');

            return $html && ($variable['html'] ?? false) !== true ? Fmt::e($sample) : $sample;
        }, (string) ($text['value'] ?? ''));
        $kind = $text['kind'] ?? null;
        if (trim($filled) === '') {
            $admin->alert('این متن خالی است و نشان داده نمی‌شود.');

            return;
        }
        if ($kind === 'popup') {
            $admin->alert(PlainWording::shown($filled));

            return;
        }

        try {
            if ($kind === 'button') {
                // Tapped, the button opens the text in its own message and takes this window away: one window stays.
                $back = Nav::to(self::ID, 'pvd', (string) ($text['key'] ?? ''), (int) $admin->ctx->update->messageId());
                $admin->ctx->reply('👁 پیش‌نمایش دکمه — با زدنش به صفحه متن برمی‌گردید:', ['reply_markup' => ['inline_keyboard' => [[['text' => $filled, 'callback_data' => $back]]]]]);
            } else {
                $admin->ctx->reply($filled, ['link_preview_options' => ['is_disabled' => true]]);
            }
            $admin->notice('👁 پیش‌نمایش زیر همین پیام فرستاده شد');
        } catch (TelegramApiException $e) {
            $admin->alert('پیش‌نمایش فرستاده نشد: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $text */
    private function variables(array $text): View
    {
        $key = (string) ($text['key'] ?? '');
        $view = View::make('🔣 متغیرهای «' . (string) ($text['title'] ?? $key) . '»', ...[...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)]);
        $variables = Json::rows($text, 'variables');
        if ($variables === []) {
            $view->line('این متن متغیری ندارد.');
        } else {
            $view->line('ربات هنگام ارسال، مقدار واقعی را جای هر متغیر می‌گذارد. متغیر «لازم» باید در متن بماند.')->line();
        }
        foreach ($variables as $variable) {
            $view->line(Fmt::code('%' . (string) ($variable['name'] ?? '') . '%') . (Json::on($variable, 'required') ? ' <b>لازم</b>' : '') . ' — ' . Fmt::e((string) ($variable['description'] ?? '')) . ' <i>(نمونه: ' . Fmt::e(strip_tags((string) ($variable['sample'] ?? ''))) . ')</i>');
        }

        return $view->back(self::ID, 'show', $key);
    }

    /**
     * The shop's default wording, a page at a time.
     *
     * @param array<string, mixed> $text
     */
    private function fallback(array $text, int $page): View
    {
        $key = (string) ($text['key'] ?? '');
        $pages = self::pages((string) ($text['default'] ?? ''));
        $page = min(count($pages), $page);
        $view = View::make('📄 متن پیش‌فرض «' . (string) ($text['title'] ?? $key) . '»', ...[...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)])
            ->line(($pages[0] === '' ? '<i>(خالی)</i>' : '<pre>' . Fmt::e($pages[$page - 1]) . '</pre>'))
            ->note("برای شروع از متن پیش‌فرض، آن را کپی کنید، تغییر دهید و با «🧾 ویرایش با کد \u{2068}HTML\u{2069}» یا «✏️ ویرایش» بفرستید؛ یا در صفحه متن «↩️ پیش‌فرض» را بزنید.");
        $view->pager($page, count($pages), self::ID, 'df', $key);

        return $view->back(self::ID, 'show', $key);
    }

    /**
     * The text of `$key` as the panel lists it, and its group's title; [null, ''] for none.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function find(Admin $admin, string $key): array
    {
        foreach (TextsScreen::groups($admin) as $group) {
            foreach ($group['texts'] as $text) {
                if (($text['key'] ?? null) === $key) {
                    return [$text, $group['title']];
                }
            }
        }

        return [null, ''];
    }

    /**
     * A wording cut into pages a screen shows, at its lines where it can.
     *
     * @return non-empty-list<string>
     */
    private static function pages(string $value): array
    {
        $pages = [];
        $page = '';
        foreach (explode("\n", $value) as $line) {
            while (mb_strlen(Fmt::e($line)) > self::WORDING_MOST) {
                if ($page !== '') {
                    $pages[] = $page;
                    $page = '';
                }
                $pages[] = mb_substr($line, 0, intdiv(self::WORDING_MOST, 2));
                $line = mb_substr($line, intdiv(self::WORDING_MOST, 2));
            }
            $next = $page === '' ? $line : $page . "\n" . $line;
            if (mb_strlen(Fmt::e($next)) > self::WORDING_MOST) {
                $pages[] = $page;
                $next = $line;
            }
            $page = $next;
        }
        $pages[] = $page;

        return $pages;
    }

    /** What a text is for, its `%variables%` kept apart in the Persian line. */
    private static function tokens(string $description): string
    {
        return (string) preg_replace('/%[A-Za-z_][A-Za-z0-9_]*%/', "\u{2068}\$0\u{2069}", Fmt::e($description));
    }
}
