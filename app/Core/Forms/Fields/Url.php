<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Http\Origin;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Validation;

/**
 * A web address (the shop's, a website's, the Bot API's, a panel's): absolute http(s) with a host, kept without its
 * trailing slash, never with credentials (addresses are shown and logged), a query or a fragment. `pages` is a pattern
 * on the path for pages it must not point into (a panel's dashboard instead of its base).
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
        /** The refusal of anything but an http(s) address with a host. */
        private readonly string $refusal,
        private readonly bool $required = true,
        private readonly ?int $max = null,
        /** The refusal of one with credentials in it, where the form has fields for them; null: `$refusal`. */
        private readonly ?string $credentials = null,
        /** A pattern on its path for the pages it must not reach into; null for none. */
        private readonly ?string $pages = null,
        /** The refusal of one that reaches into them. */
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
        // A port typed on a Persian keyboard («:۲۰۵۳») is the port.
        $url = rtrim(Persian::latinDigits(Input::text($input, $this->name)), '/');
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
