<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

// Sends via SendGrid's HTTPS v3 API (https://api.sendgrid.com/v3/mail/send)
// rather than SMTP. Railway blocks raw outbound SMTP (ports 25/587) on
// Free/Hobby plans, which left forgot-password timing out indefinitely
// against Gmail SMTP even with correct credentials - confirmed live
// (10s timeout, clean 503, but the connection itself never completed).
// A plain HTTPS POST is unaffected since it's indistinguishable from any
// other web request. Written directly against SendGrid's REST API instead
// of relying on a third-party Symfony Mailer bridge package's DSN/
// auto-discovery behavior, which couldn't be verified without a live key.
class SendGridApiTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException('SendGridApiTransport only supports Symfony Mime Email messages.');
        }

        $from = $email->getFrom()[0] ?? null;
        if (!$from) {
            throw new TransportException('SendGrid requires a From address.');
        }

        $content = [];
        if ($text = $email->getTextBody()) {
            $content[] = ['type' => 'text/plain', 'value' => $text];
        }
        if ($html = $email->getHtmlBody()) {
            $content[] = ['type' => 'text/html', 'value' => $html];
        }
        if (!$content) {
            throw new TransportException('SendGrid requires at least a text or HTML body.');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(10)
            ->post('https://api.sendgrid.com/v3/mail/send', [
                'personalizations' => [[
                    'to' => array_map([self::class, 'addressToArray'], $email->getTo()),
                ]],
                'from' => self::addressToArray($from),
                'subject' => (string) $email->getSubject(),
                'content' => $content,
            ]);

        if ($response->failed()) {
            throw new TransportException(
                'SendGrid API error ('.$response->status().'): '.$response->body()
            );
        }
    }

    private static function addressToArray(Address $address): array
    {
        return array_filter([
            'email' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ]);
    }

    public function __toString(): string
    {
        return 'sendgrid+api';
    }
}
