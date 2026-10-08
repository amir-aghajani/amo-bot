<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Core\Exceptions\ValidationException;
use App\Modules\Settings\Services\Settings;
use App\Support\Input;
use Psr\Log\LoggerInterface;

/**
 * The bot's keyboards as the admin laid them out, stored in the settings table (`bot.keyboard.<name>`)
 * with a built-in default per keyboard. Only the start menu exists today; more screens register here.
 * A layout is read back through the same checks it was saved with: one that would not pass them (a row changed by
 * hand) is not the menu — the built-in one is, so a bad row can never take the bot's menu away. A shop's layout has only
 * the actions its menu has — none the bot no longer has (MainMenu::ACTIONS), no «نمایندگی» in an agent's bot
 * (MainMenu::absent()): a save refuses another, and one kept from before reads without it.
 */
final class KeyboardLayouts
{
    public const START = 'start';

    /** name => admin-facing title */
    public const NAMES = [self::START => 'منوی شروع'];

    public const MAX_ROWS = 12;
    /** Telegram caps an inline row at 8 buttons; the same keeps reply keyboards readable. */
    public const MAX_PER_ROW = 8;
    public const LABEL_MAX = 64;

    /** A premium emoji's tag, or what is left of one, in a label. */
    private const PREMIUM_TAG = '~</?tg-emoji~i';

    private const PREMIUM_IN_LABEL = 'ایموجی پرمیوم در متن دکمه به صورت کد دیده می‌شود؛ آن را به عنوان آیکون دکمه انتخاب کنید.';

    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::NAMES);
    }

    public function get(string $name): KeyboardLayout
    {
        $default = self::defaultFor($name);
        $stored = $this->settings->get(self::key($name));
        if ($stored === null) {
            return $default;
        }

        try {
            $layout = self::validate(is_array($stored) ? $stored : [], saving: false);
        } catch (ValidationException) {
            $this->logger->warning('The stored "{name}" keyboard is not a layout the editor would save; the built-in one is shown', ['name' => $name]);

            return $default;
        }

        // Nothing left of it in this shop: the built-in menu, never an empty one.
        return $layout->rows === [] ? $default : $layout;
    }

    public function isDefault(string $name): bool
    {
        return $this->settings->get(self::key($name)) === null;
    }

    /**
     * Validate the editor's layout (Persian messages) and store it.
     *
     * @param array<string, mixed> $input {type, rows: [[{action, label, style, icon}]]}
     * @throws ValidationException
     */
    public function save(string $name, array $input): KeyboardLayout
    {
        self::defaultFor($name); // unknown names throw here
        $layout = self::validate($input, saving: true);
        $this->settings->set(self::key($name), $layout->toArray());

        return $layout;
    }

    /** Back to the built-in layout. */
    public function reset(string $name): KeyboardLayout
    {
        $this->settings->forget(self::key($name));

        return self::defaultFor($name);
    }

    /** @return array<string, mixed> What the editor loads. */
    public function present(string $name): array
    {
        return [
            'name' => $name,
            'title' => self::NAMES[$name] ?? $name,
            'is_default' => $this->isDefault($name),
        ] + $this->get($name)->toArray();
    }

    /**
     * @param array<string, mixed> $input
     * @param bool $saving The editor's layout, of the current shop's actions alone — not one kept, which may carry an
     *                     action its shop does not have (read without it, and without a row it leaves empty: get() shows
     *                     the built-in menu for one left with nothing)
     * @throws ValidationException
     */
    private static function validate(array $input, bool $saving): KeyboardLayout
    {
        $errors = [];

        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        if (!in_array($type, KeyboardLayout::TYPES, true)) {
            $errors['type'][] = 'نوع کیبورد باید «پایین صفحه» یا «شیشه‌ای» باشد.';
        }

        $rawRows = is_array($input['rows'] ?? null) ? array_values($input['rows']) : [];
        $rows = [];
        $seenActions = [];
        $seenLabels = [];
        foreach ($rawRows as $r => $rawRow) {
            $rawRow = is_array($rawRow) ? array_values($rawRow) : [];
            if ($rawRow === []) {
                continue; // an emptied row simply disappears
            }
            if (count($rawRow) > self::MAX_PER_ROW) {
                $errors["rows.{$r}"][] = 'هر ردیف حداکثر ' . self::MAX_PER_ROW . ' دکمه می‌گیرد.';
            }

            $row = [];
            foreach ($rawRow as $b => $rawButton) {
                $field = "rows.{$r}.{$b}";
                $rawButton = is_array($rawButton) ? $rawButton : [];
                $action = is_string($rawButton['action'] ?? null) ? $rawButton['action'] : '';
                $label = Input::text($rawButton, 'label');
                $style = $rawButton['style'] ?? null;
                $rawIcon = Input::text($rawButton, 'icon');
                $icon = preg_match(KeyboardLayout::ICON_PATTERN, $rawIcon) === 1 ? $rawIcon : null;

                if (!MainMenu::hasAction($action) || in_array($action, MainMenu::absent(), true)) {
                    if (!$saving) {
                        continue;
                    }
                    $errors[$field][] = 'این دکمه در ربات وجود ندارد.';
                } elseif (isset($seenActions[$action])) {
                    $errors[$field][] = 'دکمه «' . MainMenu::ACTIONS[$action]['title'] . '» دو بار در کیبورد آمده است.';
                }
                $seenActions[$action] = true;

                if ($label === '') {
                    $errors[$field][] = 'متن دکمه خالی است.';
                } elseif (preg_match(self::PREMIUM_TAG, $label) === 1) {
                    // A label is plain text to Telegram: the tag (pasted from /emoji's template) would be on the button as it is.
                    $errors[$field][] = self::PREMIUM_IN_LABEL;
                } elseif (mb_strlen($label) > self::LABEL_MAX) {
                    $errors[$field][] = 'متن دکمه حداکثر ' . self::LABEL_MAX . ' کاراکتر است.';
                } elseif (isset($seenLabels[$label])) {
                    $errors[$field][] = 'دو دکمه نمی‌توانند یک متن داشته باشند؛ ربات از روی متن می‌فهمد کدام دکمه زده شده.';
                }
                $seenLabels[$label] = true;

                if ($style !== null && $style !== '' && !in_array($style, KeyboardLayout::STYLES, true)) {
                    $errors[$field][] = 'رنگ دکمه باید پیش‌فرض، اصلی، موفقیت یا خطر باشد.';
                }
                if ($icon === null && $rawIcon !== '') {
                    $errors[$field][] = 'آیکون دکمه باید شناسه یک ایموجی پرمیوم باشد.';
                }

                $row[] = ['action' => $action, 'label' => $label, 'style' => in_array($style, KeyboardLayout::STYLES, true) ? $style : null, 'icon' => $icon];
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        if ($rows === [] && $saving) {
            $errors['rows'][] = 'کیبورد دست‌کم یک دکمه لازم دارد.';
        } elseif (count($rows) > self::MAX_ROWS) {
            $errors['rows'][] = 'کیبورد حداکثر ' . self::MAX_ROWS . ' ردیف می‌گیرد.';
        }

        ValidationException::ifAny($errors);

        return new KeyboardLayout($type, $rows);
    }

    private static function defaultFor(string $name): KeyboardLayout
    {
        return match ($name) {
            self::START => MainMenu::defaultLayout(),
            default => throw new \InvalidArgumentException("Unknown keyboard \"{$name}\"."),
        };
    }

    private static function key(string $name): string
    {
        return "bot.keyboard.{$name}";
    }
}
