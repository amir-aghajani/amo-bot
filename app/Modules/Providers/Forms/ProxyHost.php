<?php

declare(strict_types=1);

namespace App\Modules\Providers\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Validation;

/**
 * The host of a panel's SOCKS5 proxy: a host name or an IP address, without a scheme or a port (the port has a field of
 * its own). An IPv6 address may be typed in brackets, an IPv4 one in Persian digits; it is kept without them, the name
 * in lower case. Required.
 *
 * @extends Field<string>
 */
final class ProxyHost extends Field
{
    /** The longest host kept (servers.proxy_host). */
    private const MAX = 255;

    private const LABEL = 'آدرس پراکسی';

    private const REFUSED = 'آدرس پراکسی باید نام هاست یا IP باشد، بدون socks5:// و بدون پورت (مثل proxy.example.com یا 203.0.113.10).';

    public function __construct(string $name, string $key, ?FieldSpec $spec = null)
    {
        parent::__construct($name, $key, '', $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Text;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): string
    {
        // An address typed on a Persian keyboard: its digits (and «٫») Latin, as no host name has them.
        $host = strtolower(Persian::latinDigits(Input::text($input, $this->name)));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === '') {
            throw new FieldRefused(self::LABEL . ' را وارد کنید.');
        }
        if (mb_strlen($host) > self::MAX) {
            throw new FieldRefused(Validation::tooLong(self::LABEL, self::MAX));
        }
        if (!self::isHost($host)) {
            throw new FieldRefused(self::REFUSED);
        }

        return $host;
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) ? $kept : $this->default;
    }

    /** An IP address, or a host name — but not digits and dots that make no IPv4 address (203.0.113.300). */
    private static function isHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^[\d.]+$/', $host) !== 1 && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
