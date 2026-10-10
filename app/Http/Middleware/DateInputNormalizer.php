<?php

namespace App\Http\Middleware;

use App\Support\DateFmt;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * 7 Oct 2026 (Ejaz) — users type / upload dates as DD-MM-YYYY; the database
 * and every controller expect ISO. Converting at the door (web routes only)
 * means every form, filter and browser-parsed import accepts DD-MM-YYYY
 * without touching each controller. The /api routes keep their strict ISO.
 */
class DateInputNormalizer extends TransformsRequest
{
    protected function transform($key, $value)
    {
        if (is_string($key) && preg_match('/password|token|secret|_key$/i', $key)) {
            return $value;
        }

        return DateFmt::toIso($value);
    }
}
