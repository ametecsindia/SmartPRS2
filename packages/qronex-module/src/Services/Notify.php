<?php

namespace Ametecs\Qronex\Services;

use Illuminate\Support\Facades\Mail;
use Throwable;

/** Plain emails through the host's own mailer (its SMTP settings). A mail failure never breaks attendance. */
class Notify
{
    public static function send(?string $to, string $subject, string $body): void
    {
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
