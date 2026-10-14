<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Support\LocalTime;

/**
 * The pattern a shop names its customers' clients by on the panels (Services\ClientNameSettings), and its variables —
 * one list, read by the setting's check, the naming (Services\ClientNaming) and the panels' hint (variables()). A
 * pattern is the characters a panel takes in a name (letters, digits and `_ - . @`) and variables in braces. Its names
 * must differ from service to service, or each would be skipped as taken until none is left: it holds {n} or {random},
 * or {seq} — a customer's own count — beside a variable that tells customers apart.
 */
final readonly class ClientNamePattern
{
    /** The shop's default: "amir_1", "amir_2" … — what every shop's clients were called before the pattern was theirs. */
    public const DEFAULT = '{username}_{seq}';

    /** A free test's default: "test_amir_3". */
    public const TEST_DEFAULT = 'test_{username}_{seq}';

    /** The longest pattern kept. */
    public const MAX_LENGTH = 48;

    /** The longest name a pattern makes (Services\ClientNaming cuts it there); `orders.client_name` holds as much. */
    public const NAME_MAX = 64;

    /** Each variable and what it stands for (the panels' hint lists them in this order). */
    public const VARIABLES = [
        'username' => 'نام کاربری تلگرام مشتری (بدون @)؛ اگر نداشته باشد USER',
        'seq' => 'شماره سرویس خود مشتری (۱، ۲، ۳ …)؛ مشتری‌های بدون نام کاربری یک شمارنده مشترک دارند',
        'tg_id' => 'شناسه عددی تلگرام مشتری؛ برای مشتری بدون تلگرام w و شماره حسابش',
        'user_id' => 'شماره حساب مشتری در فروشگاه',
        'n' => 'شماره سرویس در کل فروشگاه (۱، ۲، ۳ …)',
        'random' => 'شش حرف و رقم تصادفی',
        'date' => 'تاریخ ساخت به میلادی و به وقت فروشگاه: سال، ماه و روز، هر کدام دو رقم',
    ];

    /** Each variable's value for the panels' sample customer — @amir's third service, the shop's 128th —, but {date}. */
    private const SAMPLE = ['username' => 'amir', 'seq' => '3', 'tg_id' => '123456789', 'user_id' => '42', 'n' => '128', 'random' => 'k7m2qx'];

    /** The variables that make a name the shop's alone: a pattern holds one of them… */
    private const OWN = ['n', 'random'];

    /** …or {seq} beside one of these, which tell customers apart. */
    private const CUSTOMER = ['username', 'tg_id', 'user_id'];

    private const VARIABLE = '/\{([a-z_]+)\}/';
    private const LITERAL = '/^[A-Za-z0-9_.@-]*$/';

    private function __construct(public string $pattern) {}

    /** The pattern, once it holds (problem() null). */
    public static function of(string $pattern): self
    {
        $problem = self::problem($pattern);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }

        return new self($pattern);
    }

    /** Why `$pattern` cannot name clients, in words for the admin; null when it can. */
    public static function problem(string $pattern): ?string
    {
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_LENGTH) {
            return sprintf('الگو باید بین 1 تا %d کاراکتر باشد.', self::MAX_LENGTH);
        }
        preg_match_all(self::VARIABLE, $pattern, $matches);
        $unknown = array_diff($matches[1], array_keys(self::VARIABLES));
        if ($unknown !== []) {
            return sprintf('متغیر {%s} شناخته نشد؛ متغیرها: %s.', reset($unknown), self::listed());
        }
        if (preg_match(self::LITERAL, (string) preg_replace(self::VARIABLE, '', $pattern)) !== 1) {
            return 'بیرون از متغیرها فقط حرف و رقم انگلیسی و _ - . @ پذیرفته می‌شود.';
        }
        $apart = array_intersect($matches[1], self::OWN) !== [] || (in_array('seq', $matches[1], true) && array_intersect($matches[1], self::CUSTOMER) !== []);
        if (!$apart) {
            return 'الگو باید {n} یا {random} را داشته باشد، یا {seq} را کنار {username}، {tg_id} یا {user_id}، تا نام کاربری سرویس‌ها تکراری نشود.';
        }

        return null;
    }

    /**
     * The variables as the panels' hint lists them: each with what it stands for and its value for a sample customer —
     * {date} the day `$now` falls on —, so the example name a panel draws as a pattern is typed is the naming's own.
     *
     * @return list<array{name: string, description: string, example: string}>
     */
    public static function variables(\DateTimeInterface $now): array
    {
        $sample = self::SAMPLE + ['date' => self::date($now)];

        return array_map(static fn(string $name): array => ['name' => $name, 'description' => self::VARIABLES[$name], 'example' => $sample[$name]], array_keys(self::VARIABLES));
    }

    /** {date}: the day `$at` falls on by the shop's clock, as YYMMDD. */
    public static function date(\DateTimeInterface $at): string
    {
        return LocalTime::of($at)->format('ymd');
    }

    /**
     * The name for these values (variable => text): each variable the pattern holds is asked for when it is needed, so a
     * counter moves only for a pattern that holds it.
     *
     * @param array<string, \Closure(): string> $values
     */
    public function render(array $values): string
    {
        return (string) preg_replace_callback(self::VARIABLE, static fn(array $match): string => ($values[$match[1]] ?? static fn(): string => '')(), $this->pattern);
    }

    /** Whether the pattern holds `$variable`. */
    public function holds(string $variable): bool
    {
        return str_contains($this->pattern, '{' . $variable . '}');
    }

    /** The variables, in braces, for a refusal. */
    private static function listed(): string
    {
        return implode('، ', array_map(static fn(string $name): string => '{' . $name . '}', array_keys(self::VARIABLES)));
    }
}
