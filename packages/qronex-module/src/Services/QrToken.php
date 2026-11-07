<?php

namespace Ametecs\Qronex\Services;

use Ametecs\Qronex\Models\Gate;

/**
 * Rotating gate QR: "QRX1.<gateId>.<interval>.<window>.<sig>".
 * window = floor(unix / interval). sig = HMAC-SHA256(gate secret + APP_KEY), 20 hex chars (80 bits — the token lives seconds).
 * ponytail: a leaked display link can be relayed remotely; mitigation level is an open founder decision.
 */
class QrToken
{
    public static function make(Gate $gate, int $interval, ?int $now = null): array
    {
        $window = intdiv($now ?? time(), $interval);

        return [
            'qr' => "QRX1.{$gate->id}.{$interval}.{$window}.".self::sign($gate, $interval, $window),
            'window' => $window,
            'expires_in' => $interval - (($now ?? time()) % $interval),
        ];
    }

    /** @return array{gate_id:int,interval:int,window:int,sig:string}|null */
    public static function parse(string $qr): ?array
    {
        if (! preg_match('/^QRX1\.(\d{1,12})\.(\d{1,3})\.(\d{1,12})\.([0-9a-f]{20})$/', trim($qr), $m) || (int) $m[2] < 1) {
            return null;
        }

        return ['gate_id' => (int) $m[1], 'interval' => (int) $m[2], 'window' => (int) $m[3], 'sig' => $m[4]];
    }

    public static function validSignature(Gate $gate, array $p): bool
    {
        return hash_equals(self::sign($gate, $p['interval'], $p['window']), $p['sig']);
    }

    /** Seconds since the token's window opened (negative = from the future). */
    public static function age(array $p, ?int $now = null): int
    {
        return ($now ?? time()) - $p['window'] * $p['interval'];
    }

    private static function sign(Gate $gate, int $interval, int $window): string
    {
        return substr(hash_hmac('sha256', "QRX1|{$gate->id}|{$interval}|{$window}", $gate->display_secret.'|'.config('app.key')), 0, 20);
    }
}
