<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * eSSL eTimeTrackLite LOCAL WebAPI (SOAP) connector.
 *
 * On-prem eTimeTrackLite exposes a SOAP web service, typically at
 *   http://<host>:81/iclock/WebAPIservice.asmx
 * Punches are pulled with the GetTransactionsLog operation using the WebAPI
 * login (NOT the desktop-app login) plus the device serial number.
 *
 * This is DIFFERENT from the eTimeOffice CLOUD API (api.etimeoffice.com) that
 * ETimeOfficeService talks to. Config still lives in the biometric_configs row
 * (provider = 'etimetracklite'); parsed punches are handed to
 * ETimeOfficeService::import() so employee matching + attendance_logs writes
 * stay shared with every other feed (source = 'etimetracklite').
 *
 * eTimeTrackLite's response layout varies across builds, so parse() is
 * deliberately tolerant (per-record + per-field delimiter sniffing, datetime
 * detection by pattern). The "Test connection" button surfaces the RAW payload
 * so the mapping can be confirmed against a live device and tuned if needed.
 */
class ETimeTrackLiteService
{
    /** Reachable when we have a URL, a serial and WebAPI credentials. */
    public static function configured(array $cfg): bool
    {
        return ! empty($cfg['base_url']) && ! empty($cfg['serial_number'])
            && ! empty($cfg['username']) && ! empty($cfg['password']);
    }

    /**
     * Normalise whatever the user typed into the WebAPIservice.asmx endpoint.
     * Accepts the full .asmx URL, a bare host[:port], or a /iclock/main.aspx URL.
     */
    public static function endpointUrl(array $cfg): string
    {
        $u = trim((string) ($cfg['base_url'] ?? ''));
        if ($u === '') {
            return '';
        }
        if (! preg_match('~^https?://~i', $u)) {
            $u = 'http://'.$u;
        }
        if (preg_match('~\.asmx(\?|$)~i', $u)) {
            return $u;   // already the full WebAPIservice.asmx URL — use as typed
        }
        // Otherwise reduce to scheme://host:port and append the standard service
        // path, so a bare host, a host:port, or a /iclock/main.aspx URL all resolve
        // to the same endpoint — and the /iclock segment is never doubled.
        $p = @parse_url($u);
        if (! $p || empty($p['host'])) {
            return $u;
        }
        $scheme = $p['scheme'] ?? 'http';
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        return $scheme.'://'.$p['host'].$port.'/iclock/WebAPIservice.asmx';
    }

    /**
     * Call GetTransactionsLog for the [from, to] window.
     *
     * @return array{ok:bool,status:int,body:string,raw:string,error:?string}
     */
    public static function fetch(array $cfg, Carbon $from, Carbon $to): array
    {
        $url = self::endpointUrl($cfg);
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'raw' => '', 'error' => 'No WebAPI URL set.'];
        }
        // eSSL's manual samples the window as yyyy/MM/dd HH:mm:ss (e.g. 2021/05/22 09:05).
        $envelope = self::buildEnvelope(
            $from->format('Y/m/d H:i:s'),
            $to->format('Y/m/d H:i:s'),
            (string) ($cfg['serial_number'] ?? ''),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );

        try {
            $resp = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => 'http://tempuri.org/GetTransactionsLog',
            ])->timeout(60)->withBody($envelope, 'text/xml; charset=utf-8')->post($url);

            $body = $resp->body();
            $raw = self::extractData($body);

            return [
                'ok' => $resp->successful(),
                'status' => $resp->status(),
                'body' => $body,
                'raw' => $raw,
                'error' => $resp->successful() ? null : ('HTTP '.$resp->status()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'raw' => '', 'error' => $e->getMessage()];
        }
    }

    private static function buildEnvelope(string $from, string $to, string $serial, string $user, string $pass): string
    {
        $x = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<GetTransactionsLog xmlns="http://tempuri.org/">'
            .'<FromDateTime>'.$x($from).'</FromDateTime>'
            .'<ToDateTime>'.$x($to).'</ToDateTime>'
            .'<SerialNumber>'.$x($serial).'</SerialNumber>'
            .'<UserName>'.$x($user).'</UserName>'
            .'<UserPassword>'.$x($pass).'</UserPassword>'
            .'<strDataList></strDataList>'
            .'</GetTransactionsLog>'
            .'</soap:Body></soap:Envelope>';
    }

    /**
     * Pull the transaction payload out of the SOAP response. eTimeTrackLite
     * returns the records inside <strDataList> (a ByRef param) and a
     * boolean/count inside <GetTransactionsLogResult>. Prefer strDataList when
     * it carries real data; surface a SOAP fault message so the UI is useful.
     */
    public static function extractData(string $soap): string
    {
        if (trim($soap) === '') {
            return '';
        }
        if (preg_match('~<faultstring[^>]*>(.*?)</faultstring>~is', $soap, $fm)) {
            return 'SOAP fault: '.trim(html_entity_decode($fm[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        foreach (['strDataList', 'GetTransactionsLogResult'] as $tag) {
            if (preg_match('~<'.$tag.'[^>]*>(.*?)</'.$tag.'>~is', $soap, $m)) {
                $val = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($val !== '' && ! in_array(strtolower($val), ['true', 'false'], true)) {
                    return $val;
                }
            }
        }

        // Nothing obviously parsable — hand back the raw body so Test still shows it.
        return trim($soap);
    }

    /**
     * Tolerantly parse the transaction payload into punch rows (emp_code is the
     * RAW device code; the emp_prefix is applied later inside import()).
     *
     * @return list<array{emp_code:string,name:?string,punch_at:Carbon,direction:string,machine:string}>
     */
    public static function parse(string $raw, array $cfg = []): array
    {
        $out = [];
        $raw = trim($raw);
        if ($raw === '' || stripos($raw, 'SOAP fault') === 0) {
            return $out;
        }
        $records = preg_split('~\r\n|\r|\n~', $raw) ?: [];
        if (count($records) <= 1 && strpos($raw, ';') !== false) {
            $records = explode(';', $raw);   // some builds return one line, ';'-separated
        }
        $inMc = trim((string) ($cfg['in_machine_id'] ?? ''));
        $outMc = trim((string) ($cfg['out_machine_id'] ?? ''));
        foreach ($records as $rec) {
            $rec = trim($rec);
            if ($rec === '') {
                continue;
            }
            $row = self::rowFromFields(self::splitFields($rec), $inMc, $outMc);
            if ($row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Split one record into fields, sniffing the delimiter (tab, pipe, comma, wide-space). */
    private static function splitFields(string $rec): array
    {
        foreach (["\t", '|', ','] as $d) {
            if (strpos($rec, $d) !== false) {
                return array_map('trim', explode($d, $rec));
            }
        }

        return array_map('trim', preg_split('~\s{2,}~', $rec) ?: [$rec]);
    }

    /** Build a punch row from loosely-ordered fields; null if no datetime found. */
    private static function rowFromFields(array $fields, string $inMc, string $outMc): ?array
    {
        $fields = array_values(array_filter($fields, fn ($f) => trim((string) $f) !== ''));
        if (! $fields) {
            return null;
        }

        // Locate the datetime — one cell "YYYY-MM-DD HH:MM:SS", or a date cell
        // immediately followed by a time cell.
        $when = null;
        $dtIdx = -1;
        $dtLen = 1;
        for ($i = 0; $i < count($fields); $i++) {
            $cand = $fields[$i];
            $span = 1;
            if (preg_match('~^(\d{4}-\d{2}-\d{2}|\d{2}[/-]\d{2}[/-]\d{4})$~', $cand)
                && isset($fields[$i + 1]) && preg_match('~^\d{1,2}:\d{2}~', $fields[$i + 1])) {
                $cand = $cand.' '.$fields[$i + 1];
                $span = 2;
            }
            $d = self::parseDate($cand);
            if ($d) {
                $when = $d;
                $dtIdx = $i;
                $dtLen = $span;
                break;
            }
        }
        if (! $when) {
            return null;
        }

        $dtCells = range($dtIdx, $dtIdx + $dtLen - 1);
        // Employee code: first non-datetime cell containing a digit (that isn't
        // itself a full datetime); else the first cell.
        $emp = null;
        foreach ($fields as $i => $f) {
            if (in_array($i, $dtCells, true)) {
                continue;
            }
            if (preg_match('~\d~', $f) && ! self::parseDate($f)) {
                $emp = $f;
                break;
            }
        }
        if ($emp === null) {
            $emp = $fields[0];
        }

        // Machine + direction. If In/Out Machine IDs are configured, a cell that
        // equals one of them fixes both. Otherwise look for an in/out token.
        $machine = '';
        $direction = null;
        foreach ($fields as $i => $f) {
            if (in_array($i, $dtCells, true) || $f === $emp) {
                continue;
            }
            if ($inMc !== '' && strcasecmp($f, $inMc) === 0) {
                $machine = $f;
                $direction = 'in';
                break;
            }
            if ($outMc !== '' && strcasecmp($f, $outMc) === 0) {
                $machine = $f;
                $direction = 'out';
                break;
            }
        }
        if ($direction === null) {
            foreach ($fields as $i => $f) {
                if (in_array($i, $dtCells, true)) {
                    continue;
                }
                $lf = strtolower(trim($f));
                if (in_array($lf, ['in', 'i', 'checkin', 'check-in', 'duty on'], true)) {
                    $direction = 'in';
                    break;
                }
                if (in_array($lf, ['out', 'o', 'checkout', 'check-out', 'duty off'], true)) {
                    $direction = 'out';
                    break;
                }
            }
        }

        return [
            'emp_code' => trim((string) $emp),
            'name' => null,
            'punch_at' => $when,
            'direction' => $direction ?: 'in',   // unknown → 'in'; tune via In/Out Machine IDs
            'machine' => $machine,
        ];
    }

    private static function parseDate(string $v): ?Carbon
    {
        $v = trim($v);
        if ($v === '' || ! preg_match('~\d~', $v)) {
            return null;
        }
        foreach ([
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'm/d/Y H:i:s', 'm/d/Y H:i',
        ] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }
}
