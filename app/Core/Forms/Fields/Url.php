<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Http\Origin;
use App\Support\Input;
use App\Support\Validation;

/**
 * A web address — the shop's, a website's, the Bot API's, a panel's —: http or https with a host, absolute, kept without
 * the slash at its end, and nothing an address of a server does not carry: no credentials (an address is shown and
 * logged; where the form has fields of their own for them, `credentials` says so), no query, no fragment. `max`
 * characters at most; blank only while it is not `required`; held off the pages it must not reach into when the field
 * names them (`pages`, a pattern on its path — a panel's own pages).
 *
 * @extends Field<string>
 */
final class Url extends Field
{
    public function __construct(
        string $name,
        string $key,
        string $default,
        /** What its refusals call it: «آدرس سایت», «آدرس پنل». */
        private readonly string $label,
        /** What one that is no http(s) address with a host is told. */
        private readonly string $refusal,
        private readonly bool $required = true,
        private readonly ?int $max = null,
        /** What one with credentials in it is told, where the form keeps them in fields of their own; null: its refusal. */
        private readonly ?string $credentials = null,
        /** A pattern on its path for the pages it must not reach into; null for none. */
        private readonly ?string $pages = null,
        /** What one that reaches into them is told. */
        private readonly string $pagesRefusal = '',
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Url;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function read(array $input, mixed $kept): string
    {
        $url = rtrim(Input::text($input, $this->name), '/');
        $problem = match (true) {
            $url === '' => $this->required ? "{$this->label} را وارد کنید." : null,
            $this->max !== null && mb_strlen($url) > $this->max => Validation::tooLong($this->label, $this->max),
            $this->credentials !== null && self::carriesCredentials($url) => $this->credentials,
            Origin::of($url) === null => $this->refusal,
            str_contains($url, '?') || str_contains($url, '#') => "{$this->label} نباید شامل پارامتر (?) یا قطعه (#) باشد.",
            $this->pages !== null && preg_match($this->pages, (string) parse_url($url, PHP_URL_PATH)) === 1 => $this->pagesRefusal,
            default => null,
        };
        if ($problem !== null) {
            throw new FieldRefused($problem);
        }

        return $url;
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) && trim($kept) !== '' ? rtrim(trim($kept), '/') : $this->default;
    }

    /** A `user:password@` part. */
    private static function carriesCredentials(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
    }
}
