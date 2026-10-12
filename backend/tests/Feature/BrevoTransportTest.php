<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BrevoTransportTest extends TestCase
{
    public function test_brevo_mailer_posts_the_email_to_the_brevo_api(): void
    {
        config(['mail.mailers.brevo.api_key' => 'test-key', 'mail.from.address' => 'sender@example.com']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);

        Mail::mailer('brevo')->to('user@example.com')->send(new PasswordResetMail('https://app.test/reset?token=abc'));

        Http::assertSent(fn ($r) => $r->url() === 'https://api.brevo.com/v3/smtp/email'
            && $r->hasHeader('api-key', 'test-key')
            && $r['to'][0]['email'] === 'user@example.com'
            && $r['sender']['email'] === 'sender@example.com'
            && str_contains($r['htmlContent'], 'https://app.test/reset?token=abc'));
    }

    public function test_brevo_error_response_surfaces_as_an_exception(): void
    {
        config(['mail.mailers.brevo.api_key' => 'bad']);
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);

        $this->expectException(\Symfony\Component\Mailer\Exception\TransportException::class);
        Mail::mailer('brevo')->to('user@example.com')->send(new PasswordResetMail('https://app.test/x'));
    }
}
