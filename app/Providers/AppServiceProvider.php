<?php

namespace App\Providers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 17 Oct 2026: Windows PHP (Laragon, client on-prem PCs) ships with no openssl.cafile,
        // so STARTTLS to Gmail etc. fails "certificate verify failed". When PHP has no CA
        // bundle of its own, point every SMTP send at the bundled Mozilla one.
        // ponytail: one global hook covers Send Test + MailService; refresh the .pem from
        // C:\laragon\etc\ssl\cacert.pem (or curl.se/ca/cacert.pem) once a year.
        Event::listen(MessageSending::class, function () {
            $ca = resource_path('certs/cacert.pem');
            $sys = openssl_get_cert_locations()['default_cert_file'] ?? '';
            if (ini_get('openssl.cafile') || is_file($sys) || ! is_file($ca)) {
                return;
            }
            $t = Mail::mailer()->getSymfonyTransport();
            if ($t instanceof EsmtpTransport && ($s = $t->getStream()) instanceof SocketStream) {
                $s->setStreamOptions(array_replace_recursive($s->getStreamOptions(), ['ssl' => ['cafile' => $ca]]));
            }
        });
    }
}
