<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A write that failed with no answer of the panel's may have been done all the same — what a move asks the panel about
 * and a grant never gives again: only once the request was on its way and nothing the panel said came back.
 */
final class ProviderExceptionTest extends TestCase
{
    /** @return iterable<string, array{ProviderException, bool}> */
    public static function failures(): iterable
    {
        yield 'a timeout' => [new ConnectionException(ConnectionFailure::Timeout, 'Operation timed out'), true];
        yield 'a connection cut off' => [new ConnectionException(ConnectionFailure::Other, 'Connection reset by peer'), true];
        yield "a proxy's gateway timeout" => [new UnexpectedResponseException(504, 'Gateway Time-out'), true];
        yield "a proxy's bad gateway" => [new UnexpectedResponseException(502, 'Bad gateway'), true];
        yield 'no address for its name' => [new ConnectionException(ConnectionFailure::Dns, 'Could not resolve host'), false];
        yield 'no connection' => [new ConnectionException(ConnectionFailure::Refused, 'Connection refused'), false];
        yield 'no TLS handshake' => [new ConnectionException(ConnectionFailure::Tls, 'SSL certificate problem'), false];
        yield 'no way through its proxy' => [new ConnectionException(ConnectionFailure::Proxy, 'SOCKS5 connect failed'), false];
        yield 'an unknown route' => [new UnexpectedResponseException(404), false];
        yield 'a redirect' => [new UnexpectedResponseException(301, location: 'https://panel.test/abc/'), false];
        yield "the panel's own refusal, a 500 too" => [new PanelApiException('The panel refused it.', 500, 'internal error'), false];
        yield 'its credentials refused' => [AuthenticationException::rejectedToken(401), false];
        yield 'no such client' => [new NotFoundException('The panel has no such client.'), false];
    }

    #[DataProvider('failures')]
    public function testAWriteMayHaveBeenDoneOnlyOnceItWasSentAndNoAnswerOfThePanelsCame(ProviderException $e, bool $mayHave): void
    {
        self::assertSame($mayHave, $e->mayHaveBeenDone());
    }
}
