<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through Brevo's HTTPS API. Railway's non-Pro plans block outbound
 * SMTP ports, but HTTPS is always open.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $format = fn (Address $a): array => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);

        $payload = [
            'sender' => $format($email->getFrom()[0]),
            'to' => array_map($format, $email->getTo()),
            'subject' => $email->getSubject(),
        ];
        if ($email->getHtmlBody() !== null) {
            $payload['htmlContent'] = $email->getHtmlBody();
        }
        if ($email->getTextBody() !== null) {
            $payload['textContent'] = $email->getTextBody();
        }
        if (!isset($payload['htmlContent']) && !isset($payload['textContent'])) {
            $payload['textContent'] = ' ';
        }

        $response = Http::withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])
            ->timeout(15)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new TransportException('Brevo rejected the email: '.$response->status().' '.$response->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
