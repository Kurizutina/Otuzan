<?php

namespace Tests\Unit;

use App\Mail\Transport\SendGridApiTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SendGridApiTransportTest extends TestCase
{
    private function send(Email $email): void
    {
        $transport = new SendGridApiTransport('fake-api-key');
        $envelope = new \Symfony\Component\Mailer\Envelope(
            $email->getFrom()[0],
            $email->getTo()
        );
        $transport->send($email, $envelope);
    }

    public function test_sends_correct_payload_to_sendgrid_api(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response(['id' => 'abc'], 202)]);

        $email = (new Email())
            ->from('Otu-Zan <sender@example.com>')
            ->to('customer@example.com')
            ->subject('Reset your password')
            ->html('<p>Click here</p>')
            ->text('Click here');

        $this->send($email);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
                && $request->hasHeader('Authorization', 'Bearer fake-api-key')
                && $request['from']['email'] === 'sender@example.com'
                && $request['from']['name'] === 'Otu-Zan'
                && $request['personalizations'][0]['to'][0]['email'] === 'customer@example.com'
                && $request['subject'] === 'Reset your password'
                && in_array(['type' => 'text/html', 'value' => '<p>Click here</p>'], $request['content'])
                && in_array(['type' => 'text/plain', 'value' => 'Click here'], $request['content']);
        });
    }

    public function test_throws_on_sendgrid_api_error(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response(['errors' => [['message' => 'bad key']]], 401)]);

        $email = (new Email())
            ->from('sender@example.com')
            ->to('customer@example.com')
            ->subject('Subject')
            ->text('Body');

        $this->expectException(TransportException::class);
        $this->send($email);
    }
}
