<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Mail\ResendTransport;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * Email through Resend's API: posted to its /emails endpoint as JSON with the account's key, through the shop's one
 * outgoing client (here the tests' fake), never following a redirect; Resend's id kept as the message's; a refusal in
 * Resend's own words with its status, the key in none; Resend out of reach a failure of the transport.
 */
final class ResendTransportTest extends TestCase
{
    private const KEY = 're_test_1234567890abcdef';

    public function testAnEmailGoesToResendsApiSignedWithTheAccountsKey(): void
    {
        $resend = new FakePanel();
        $resend->raw(new Response(200, ['Content-Type' => 'application/json'], '{"id":"49a3999c-0ce1-4ea6-ab68-afcd6dc2e794"}'));

        $sent = (new ResendTransport($resend->client(), self::KEY))->send(self::email());

        $request = $resend->request(0);
        self::assertSame('POST ' . ResendTransport::ENDPOINT, $request->getMethod() . ' ' . $request->getUri());
        self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        self::assertSame([
            'from' => '"فروشگاه \"آب‌سردکن\"" <no-reply@shop.example>',
            'to' => ['sara@example.com'],
            'subject' => 'کد تایید',
            'html' => '<p>کد شما ۱۲۳۴۵۶</p>',
            'text' => 'کد شما ۱۲۳۴۵۶',
        ], $resend->params(0), 'the name as written, quoted — not MIME-encoded as a header would carry it');
        self::assertFalse($resend->options(0)['allow_redirects'] ?? null, 'the key goes to Resend and nowhere else');
        self::assertSame('49a3999c-0ce1-4ea6-ab68-afcd6dc2e794', $sent?->getMessageId(), "Resend's id is the message's");
    }

    public function testAnAddressWithoutANameGoesBare(): void
    {
        $resend = new FakePanel();
        $resend->raw(new Response(200, [], '{"id":"x"}'));

        (new ResendTransport($resend->client(), self::KEY))->send((new Email())->from('no-reply@shop.example')->to('sara@example.com')->subject('s')->text('t'));

        self::assertSame(['from' => 'no-reply@shop.example', 'to' => ['sara@example.com'], 'subject' => 's', 'text' => 't'], $resend->params(0), 'no html sent for an email without one');
    }

    public function testResendsRefusalIsItsOwnWordsWithItsStatusAndNeverTheKey(): void
    {
        $resend = new FakePanel();
        $resend->raw(new Response(403, ['Content-Type' => 'application/json'], '{"statusCode":403,"name":"validation_error","message":"The shop.example domain is not verified. Please, add and verify your domain on https://resend.com/domains"}'));

        try {
            (new ResendTransport($resend->client(), self::KEY))->send(self::email());
            self::fail('Resend refused it');
        } catch (TransportException $e) {
            self::assertSame('Resend refused the email (403): The shop.example domain is not verified. Please, add and verify your domain on https://resend.com/domains', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testAnAnswerThatIsNoJsonIsARefusalWithoutReason(): void
    {
        $resend = new FakePanel();
        $resend->raw(new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Resend refused the email (502): no reason given');
        (new ResendTransport($resend->client(), self::KEY))->send(self::email());
    }

    public function testResendOutOfReachIsAFailureOfTheTransport(): void
    {
        $resend = new FakePanel();
        $resend->refuse(new ConnectException('cURL error 6: Could not resolve host: api.resend.com', new Request('POST', ResendTransport::ENDPOINT)));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Resend could not be reached: cURL error 6: Could not resolve host: api.resend.com');
        (new ResendTransport($resend->client(), self::KEY))->send(self::email());
    }

    private static function email(): Email
    {
        return (new Email())
            ->from(new Address('no-reply@shop.example', 'فروشگاه "آب‌سردکن"'))
            ->to('sara@example.com')
            ->subject('کد تایید')
            ->text('کد شما ۱۲۳۴۵۶')
            ->html('<p>کد شما ۱۲۳۴۵۶</p>');
    }
}
