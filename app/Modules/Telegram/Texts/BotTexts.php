<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Core\Exceptions\ValidationException;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Messages;
use App\Support\Validation;

/**
 * The bot's texts as the admin worded them («متن‌های ربات»), kept in the settings under `bot.text.<key>`. A wording
 * equal to the default is not stored, so an improved default still reaches the shop. A wording is checked before it is
 * stored (length, variables, Telegram HTML): a text Telegram refuses would leave the customer with nothing.
 *
 * Escaping happens here alone: a value is plain text, escaped into an HTML text and left as it is in a popup or a
 * button; a part (an Html, from part()) goes into another text as it is.
 */
final class BotTexts
{
    private const PREFIX = 'bot.text.';
    private const VARIABLE = '/%([A-Za-z_][A-Za-z0-9_]*)%/';

    public function __construct(private readonly Settings $settings) {}

    /** A text without variables (one with them is render()'s). */
    public function get(BotText $text): string
    {
        return $this->compose(self::whole($text), []);
    }

    /** A text without variables, ready for a message: a popup's text, sent as a message instead, is escaped. */
    public function message(BotText $text): string
    {
        $value = $this->get($text);

        return $text->spec()->kind->html() ? $value : htmlspecialchars($value);
    }

    /**
     * The text with exactly its declared variables filled in (a mismatch is the code's mistake, a LogicException).
     *
     * @param array<string, scalar|Html> $values
     */
    public function render(BotText $text, array $values): string
    {
        return $this->compose(self::whole($text), $values);
    }

    /**
     * A part (TextKind::Part) with its variables filled, as Html for another text to take in; empty when the admin
     * emptied it.
     *
     * @param array<string, scalar|Html> $values
     */
    public function part(BotText $text, array $values = []): Html
    {
        if ($text->spec()->kind !== TextKind::Part) {
            throw new \LogicException("The bot text \"{$text->value}\" is not a part: get() or render() it.");
        }

        return Html::of($this->compose($text, $values));
    }

    /** The greeting: by the customer's Telegram name when they have one. */
    public function welcome(?string $name): string
    {
        return $name === null ? $this->get(BotText::WelcomeNameless) : $this->render(BotText::Welcome, ['name' => $name]);
    }

    /**
     * Texts one under another with a blank line between; an emptied part leaves no gap, and a part's own leading line
     * breaks give way to the blank line.
     */
    public static function paragraphs(string|Html ...$texts): string
    {
        $texts = array_map(static fn(string|Html $text): string => ltrim((string) $text, "\n"), $texts);

        return implode("\n\n", array_filter($texts, static fn(string $text): bool => $text !== ''));
    }

    /** Whether the admin reworded the text (a wording other than the default is stored). */
    public function isCustomized(BotText $text): bool
    {
        return is_string($this->settings->get(self::key($text)));
    }

    /**
     * Check and store the admin's wording (the default's own wording clears it).
     *
     * @throws ValidationException on `value`
     */
    public function save(BotText $text, mixed $input): void
    {
        if (!is_string($input)) {
            throw new ValidationException(['value' => ['متن را وارد کنید.']]);
        }

        $spec = $text->spec();
        $value = str_replace(["\r\n", "\r"], "\n", $input);
        // A part keeps its leading line breaks: they are how it sits in the text that takes it in.
        $value = $spec->kind === TextKind::Part ? rtrim($value) : trim($value);

        $problem = self::problem($spec, $value);
        if ($problem !== null) {
            throw new ValidationException(['value' => [$problem]]);
        }

        if ($value === $spec->default) {
            $this->settings->forget(self::key($text));
        } else {
            $this->settings->set(self::key($text), $value);
        }
    }

    /** Back to the shop's wording. */
    public function reset(BotText $text): void
    {
        $this->settings->forget(self::key($text));
    }

    /** What is wrong with a wording, worded for the admin, or null. */
    public static function problem(TextSpec $spec, string $value): ?string
    {
        $kind = $spec->kind;
        if ($value === '' && $kind !== TextKind::Part) {
            return 'متن نمی‌تواند خالی باشد.';
        }
        // Counted as Telegram counts what the customer sees: UTF-16 units, tags free.
        if (($kind->html() ? TelegramHtml::visibleLength($value) : Limits::length($value)) > $kind->limit()) {
            return Validation::tooLong('متن', $kind->limit());
        }
        if ($kind === TextKind::Button && str_contains($value, "\n")) {
            return 'متن دکمه باید یک خط باشد.';
        }

        preg_match_all(self::VARIABLE, $value, $matches);
        foreach (array_unique($matches[1]) as $name) {
            if (!array_key_exists($name, $spec->variables)) {
                return $spec->variables === []
                    ? "این متن متغیری ندارد؛ %{$name}% را بردارید."
                    : "متغیر %{$name}% در این متن وجود ندارد.";
            }
        }
        foreach ($spec->required as $name) {
            if (!in_array($name, $matches[1], true)) {
                return "متغیر %{$name}% باید در متن بماند.";
            }
        }

        if (!$kind->html()) {
            return preg_match('/<\/?[a-z][a-z-]*[^>]*>/i', $value) === 1
                ? 'این متن قالب‌بندی ندارد و تگ‌ها همان‌طور که نوشته شده‌اند نشان داده می‌شوند؛ آن‌ها را بردارید.'
                : null;
        }

        return TelegramHtml::problem($value);
    }

    /**
     * The screen's list: every group the current bot says (TextCatalog::groups()) in order, each with its texts.
     *
     * @return list<array{key: string, title: string, texts: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $groups = [];
        foreach (TextCatalog::groups() as $key => $title) {
            $groups[$key] = ['key' => $key, 'title' => $title, 'texts' => []];
        }
        foreach (BotText::cases() as $text) {
            if (TextCatalog::says($text)) {
                $groups[$text->spec()->group]['texts'][] = $this->present($text);
            }
        }

        return array_values($groups);
    }

    /** @return array<string, mixed> */
    public function present(BotText $text): array
    {
        $spec = $text->spec();
        $variables = [];
        foreach ($spec->variables as $name => $description) {
            [$meaning, $sample] = TextCatalog::VARIABLES[$name];
            $variables[] = [
                'name' => $name,
                'description' => $description ?? $meaning,
                'sample' => $sample,
                'required' => in_array($name, $spec->required, true),
            ];
        }

        return [
            'key' => $text->value,
            'group' => $spec->group,
            'kind' => $spec->kind->value,
            'html' => $spec->kind->html(),
            'limit' => $spec->kind->limit(),
            'title' => $spec->title,
            'description' => $spec->description,
            'variables' => $variables,
            'default' => $spec->default,
            'value' => $this->template($text),
            'customized' => $this->isCustomized($text),
        ];
    }

    /** The wording in use, variables unfilled. */
    private function template(BotText $text): string
    {
        $wording = $this->settings->get(self::key($text));

        return is_string($wording) ? $wording : $text->spec()->default;
    }

    /**
     * The wording in use with its variables filled and escaped by the text's kind (get(), render() and part()).
     *
     * @param array<string, scalar|Html> $values
     */
    private function compose(BotText $text, array $values): string
    {
        $spec = $text->spec();
        $declared = array_keys($spec->variables);
        $given = array_keys($values);
        if (array_diff($declared, $given) !== [] || array_diff($given, $declared) !== []) {
            throw new \LogicException(sprintf(
                'The bot text "%s" takes [%s], was given [%s].',
                $text->value,
                implode(', ', $declared),
                implode(', ', $given),
            ));
        }

        $html = $spec->kind->html();
        $filled = [];
        foreach ($values as $name => $value) {
            $filled[$name] = match (true) {
                $value instanceof Html => $html ? $value->html : throw new \LogicException("The bot text \"{$text->value}\" is shown as typed: %{$name}% takes plain text."),
                $html => htmlspecialchars((string) $value),
                default => (string) $value,
            };
        }

        return Messages::fill($this->template($text), $filled);
    }

    /** A text sent on its own (get(), render()) — a part goes into another text, as part() makes it. */
    private static function whole(BotText $text): BotText
    {
        if ($text->spec()->kind === TextKind::Part) {
            throw new \LogicException("The bot text \"{$text->value}\" is a part of other texts: part() it.");
        }

        return $text;
    }

    private static function key(BotText $text): string
    {
        return self::PREFIX . $text->value;
    }
}
