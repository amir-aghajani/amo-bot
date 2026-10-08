<?php

declare(strict_types=1);

namespace Tests\Unit\Accounts;

use App\Modules\Accounts\Support\DeviceName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A device as the customer's session list names it, from its browser's User-Agent: the browser built on another told
 * apart from it (Edge says Chrome, Chrome says Safari), the system told apart the same way (Android says Linux, an
 * iPhone says Mac OS X); what it does not know, said in words.
 */
final class DeviceNameTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function browsers(): array
    {
        return [
            'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome در Windows'],
            'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.3537.57', 'Edge در Windows'],
            'Chrome on Android' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome در Android'],
            'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36', 'Samsung Internet در Android'],
            'Safari on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari در iOS'],
            'Chrome on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1', 'Chrome در iOS'],
            'Firefox on a Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox در macOS'],
            'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox در Linux'],
            'an app on Android' => ['okhttp/4.12.0 (Linux; Android 13)', 'مرورگر در Android'],
            'a browser on nothing it knows' => ['Mozilla/5.0 Firefox/131.0', 'Firefox'],
            'a script' => ['curl/8.9.1', 'دستگاه ناشناس'],
            'nothing' => ['', 'دستگاه ناشناس'],
        ];
    }

    #[DataProvider('browsers')]
    public function testADeviceIsNamedByItsBrowserAndItsSystem(string $userAgent, string $name): void
    {
        self::assertSame($name, DeviceName::of($userAgent));
    }
}
