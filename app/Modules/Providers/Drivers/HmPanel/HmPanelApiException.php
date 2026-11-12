<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\HmPanel;

use App\Modules\Providers\Exceptions\PanelApiException;

/**
 * HMPanel answered and refused the call: `panelMessage` is its `message` (a name taken on that panel, a client limit
 * reached, a cap of the admin's, inbounds of two panels); the message names the call, for the log. A key past its
 * requests per minute (rateLimited()) refuses every call for a while, so that one counts as the panel being out of
 * reach. An error of its own (a 5xx) on a write may have come after the write was done (mayHaveBeenDone()).
 */
final class HmPanelApiException extends PanelApiException
{
    /** What to do about a key past its requests per minute, in HMPanel's own terms. */
    private const HINT_RATE = 'HMPanel هر کلید API را به تعدادی درخواست در دقیقه محدود می‌کند (پیش‌فرض 60)؛ فروشگاه کمی بعد دوباره امتحان می‌کند. برای فروشگاه پرکار، در «تنظیمات ← API» پنل کلیدی با سقف درخواست بالاتر بسازید (تا 600 در دقیقه).';

    /** HMPanel's own words for inbounds of two panels on one client. */
    private const SEVERAL_PANELS = 'All selected inbounds must belong to the same panel. Create a separate client for each panel.';

    private const HINT_ONE_PANEL = 'هر کاربر HMPanel فقط روی یک پنل ساخته می‌شود و اینباندهای این فروش از چند پنل زیرمجموعه آن هستند؛ در پلن، یا در اینباندهای «فروش» این سرور، فقط اینباندهای یک پنل را نگه دارید.';

    /** Whether the call was a write: anything but a GET. */
    private readonly bool $write;

    public function __construct(string $method, string $path, int $httpStatus, string $panelMessage, string $hint = '')
    {
        parent::__construct(self::callFailed('HMPanel', $method, $path, $httpStatus, $panelMessage), $httpStatus, $panelMessage, $hint);
        $this->write = $method !== 'GET';
    }

    /** The key's requests per minute used up (HTTP 429), with what to do about it. */
    public static function rateLimited(string $method, string $path, string $panelMessage): self
    {
        return new self($method, $path, 429, $panelMessage, self::HINT_RATE);
    }

    /**
     * A client asked for on inbounds of two of HMPanel's panels, refused as the panel would refuse it — said before
     * anything is sent: a client lives on one panel.
     */
    public static function severalPanels(string $method, string $path): self
    {
        return new self($method, $path, 400, self::SEVERAL_PANELS, self::HINT_ONE_PANEL);
    }

    /** A rate limit refuses every call until its minute is over: the server is left alone a while, not asked again at once. */
    public function unavailable(): bool
    {
        return $this->httpStatus === 429;
    }

    /**
     * A 5xx on a write is HMPanel's own error, which may have come after its database took the write (a client made, its
     * answer failed): asked about before it is done again, never made a second time under the next name.
     */
    public function mayHaveBeenDone(): bool
    {
        return parent::mayHaveBeenDone() || ($this->write && $this->httpStatus >= 500);
    }
}
