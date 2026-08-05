<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Configurable "Custom / Generic API" attendance connector.
 *
 * One connector that any HTTP-based device/software API can be driven through
 * WITHOUT new PHP per vendor — the admin describes the vendor's API on the
 * Biometric Device Setup screen and it is stored as JSON in biometric_configs.
 * The parsed punches go through ETimeOfficeService::import() like every feed
 * (source = 'generic').
 *
 * What it does NOT do: guess an unknown vendor's field names. Someone must map
 * "employee code = field X, datetime = field Y" once per vendor — this executes
 * that recipe. The Test button surfaces the RAW response to build the mapping.
 *
 * The config (cfg['api_config'], a JSON string or array) understands:
 *   method        GET | POST
 *   url           request URL; placeholders {from} {to} {from_date} {to_date}
 *                 {serial} {user} {pass} {key} {token} {empcode}
 *   basic_auth    bool — send username/password as HTTP Basic
 *   headers       one "Key: Value" per line (values take placeholders)
 *   body_type     none | form | json | xml   (POST body)
 *   body          body template (placeholders substituted)
 *   date_format_req  PHP format for {from}/{to} (default 'Y-m-d H:i:s')
 *   token_url / token_method / token_body / token_path
 *                 OPTIONAL pre-auth: call token_url first, pull a token out of
 *                 the JSON response at token_path, expose it as {token}
 *   response_type json | xml | delimited
 *   record_path   JSON dot-path to the array (blank = root) | XML record tag
 *   record_delim / field_delim   delimited only (accepts \n \t \r or literals)
 *   skip_header   delimited — drop the first row
 *   map.emp / map.datetime / map.date / map.time / map.direction / map.machine
 *                 field key (JSON key / dot-path, XML tag/attr, or column index)
 *   punch_date_format  PHP format to parse the punch datetime (blank = auto)
 *   dir_in / dir_out   comma lists of tokens meaning IN / OUT
 */
class GenericApiService
{
    /** Decode cfg['api_config'] (JSON string or array) into an array. */
    public static function cfgArr(array $cfg): array
    {
        $a = $cfg['api_config'] ?? null;
        if (is_string($a)) {
            $a = json_decode($a, true);
        }

        return is_array($a) ? $a : [];
    }

    public static function configured(array $cfg): bool
    {
        $a = self::cfgArr($cfg);

        return ! empty($a['url']) && ! empty($a['response_type']);
    }

    /**
     * Run the configured request for [from, to].
     *
     * @return array{ok:bool,status:int,body:string,raw:string,error:?string}
     */
    public static function fetch(array $cfg, Carbon $from, Carbon $to): array
    {
        $a = self::cfgArr($cfg);
        $url = trim((string) ($a['url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'raw' => '', 'error' => 'No API URL set.'];
        }

        $repl = self::placeholders($cfg, $a, $from, $to);

        // Optional token pre-auth (BioTime / COSEC style): POST creds, pull token.
        if (! empty($a['token_url'])) {
            $tok = self::fetchToken($cfg, $a, $repl);
            if ($tok['error']) {
                return ['ok' => false, 'status' => $tok['status'], 'body' => $tok['body'], 'raw' => $tok['body'], 'error' => 'Token login failed: '.$tok['error']];
            }
            $repl['{token}'] = $tok['token'];
        } elseif (! isset($repl['{token}'])) {
            $repl['{token}'] = '';
        }

        try {
            $resp = self::send(
                strtoupper((string) ($a['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET',
                strtr($url, $repl),
                $a,
                $repl,
                (string) ($cfg['username'] ?? ''),
                (string) ($cfg['password'] ?? '')
            );
            $body = $resp->body();

            return [
                'ok' => $resp->successful(),
                'status' => $resp->status(),
                'body' => $body,
                'raw' => $body,
                'error' => $resp->successful() ? null : ('HTTP '.$resp->status()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'raw' => '', 'error' => $e->getMessage()];
        }
    }

    /** Build + send one HTTP request per the config. */
    private static function send(string $method, string $url, array $a, array $repl, string $user, string $pass)
    {
        $req = Http::timeout(60);
        $headers = self::parseHeaders((string) ($a['headers'] ?? ''), $repl);
        if (! empty($a['basic_auth'])) {
            $req = $req->withBasicAuth($user, $pass);
        }
        if ($headers) {
            $req = $req->withHeaders($headers);
        }
        if ($method === 'GET') {
            return $req->get($url);
        }
        $bodyType = strtolower((string) ($a['body_type'] ?? 'none'));
        $body = strtr((string) ($a['body'] ?? ''), $repl);
        switch ($bodyType) {
            case 'json':
                return $req->withBody($body, 'application/json')->post($url);
            case 'xml':
                return $req->withBody($body, $headers['Content-Type'] ?? 'text/xml; charset=utf-8')->post($url);
            case 'form':
                parse_str($body, $form);

                return $req->asForm()->post($url, is_array($form) ? $form : []);
            default:
                return $req->post($url);
        }
    }

    /** OPTIONAL token pre-auth. Returns [token,error,status,body]. */
    private static function fetchToken(array $cfg, array $a, array $repl): array
    {
        try {
            $method = strtoupper((string) ($a['token_method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST';
            $url = strtr((string) $a['token_url'], $repl);
            $req = Http::timeout(60);
            $headers = self::parseHeaders((string) ($a['token_headers'] ?? ''), $repl);
            if ($headers) {
                $req = $req->withHeaders($headers);
            }
            $body = strtr((string) ($a['token_body'] ?? ''), $repl);
            if ($method === 'GET') {
                $resp = $req->get($url);
            } elseif (($a['token_body_type'] ?? 'json') === 'form') {
                parse_str($body, $form);
                $resp = $req->asForm()->post($url, is_array($form) ? $form : []);
            } else {
                $resp = $req->withBody($body ?: '{}', 'application/json')->post($url);
            }
            if (! $resp->successful()) {
                return ['token' => '', 'error' => 'HTTP '.$resp->status(), 'status' => $resp->status(), 'body' => $resp->body()];
            }
            $json = json_decode($resp->body(), true);
            $path = trim((string) ($a['token_path'] ?? 'token'));
            $tok = is_array($json) ? self::dotGet($json, $path) : null;
            if (! is_string($tok) && ! is_numeric($tok)) {
                return ['token' => '', 'error' => 'no token at "'.$path.'"', 'status' => $resp->status(), 'body' => $resp->body()];
            }

            return ['token' => (string) $tok, 'error' => null, 'status' => $resp->status(), 'body' => $resp->body()];
        } catch (\Throwable $e) {
            return ['token' => '', 'error' => $e->getMessage(), 'status' => 0, 'body' => ''];
        }
    }

    private static function placeholders(array $cfg, array $a, Carbon $from, Carbon $to): array
    {
        $fmt = trim((string) ($a['date_format_req'] ?? '')) ?: 'Y-m-d H:i:s';

        return [
            '{from}' => $from->format($fmt),
            '{to}' => $to->format($fmt),
            '{from_date}' => $from->format('Y-m-d'),
            '{to_date}' => $to->format('Y-m-d'),
            '{serial}' => (string) ($cfg['serial_number'] ?? ''),
            '{user}' => (string) ($cfg['username'] ?? ''),
            '{pass}' => (string) ($cfg['password'] ?? ''),
            '{key}' => (string) ($a['api_key'] ?? ''),
            '{empcode}' => (string) ($cfg['empcode'] ?? 'ALL'),
        ];
    }

    /** "Key: Value" lines → header map, with placeholder substitution on values. */
    private static function parseHeaders(string $raw, array $repl): array
    {
        $out = [];
        foreach (preg_split('~\r\n|\r|\n~', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = trim(strtr($v, $repl));
            }
        }

        return $out;
    }

    /**
     * Map a raw response into punch rows.
     *
     * @return list<array{emp_code:string,name:?string,punch_at:Carbon,direction:string,machine:string}>
     */
    public static function parse(string $raw, array $cfg): array
    {
        $a = self::cfgArr($cfg);
        $type = strtolower((string) ($a['response_type'] ?? 'json'));
        $records = $type === 'xml'
            ? self::xmlRecords($raw, $a)
            : ($type === 'delimited' ? self::delimRecords($raw, $a) : self::jsonRecords($raw, $a));

        $inMc = trim((string) ($cfg['in_machine_id'] ?? ''));
        $outMc = trim((string) ($cfg['out_machine_id'] ?? ''));
        $out = [];
        foreach ($records as $rec) {
            $row = self::mapRecord($rec, $a, $inMc, $outMc);
            if ($row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private static function jsonRecords(string $raw, array $a): array
    {
        $data = json_decode(trim($raw), true);
        if (! is_array($data)) {
            return [];
        }
        $path = trim((string) ($a['record_path'] ?? ''));
        if ($path !== '') {
            $data = self::dotGet($data, $path);
        }
        if (! is_array($data)) {
            return [];
        }

        return self::isList($data) ? $data : [$data];
    }

    private static function xmlRecords(string $raw, array $a): array
    {
        $tag = trim((string) ($a['record_path'] ?? ''));
        $raw = trim($raw);
        if ($raw === '' || $tag === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($raw);
        libxml_use_internal_errors($prev);
        if (! $ok) {
            return [];
        }
        $out = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            if (strcasecmp($el->localName, $tag) !== 0) {
                continue;
            }
            $row = [];
            if ($el->hasAttributes()) {
                foreach ($el->attributes as $at) {
                    $row[$at->nodeName] = $at->nodeValue;
                }
            }
            foreach ($el->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) {
                    $row[$c->localName] = trim($c->textContent);
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function delimRecords(string $raw, array $a): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $rd = self::unescape((string) ($a['record_delim'] ?? '\n')) ?: "\n";
        $fd = self::unescape((string) ($a['field_delim'] ?? '\t')) ?: "\t";
        $lines = explode($rd, $raw);
        if (! empty($a['skip_header'])) {
            array_shift($lines);
        }
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line, "\r\n");
            if (trim($line) === '') {
                continue;
            }
            $out[] = array_map('trim', explode($fd, $line));
        }

        return $out;
    }

    private static function mapRecord($rec, array $a, string $inMc, string $outMc): ?array
    {
        $map = is_array($a['map'] ?? null) ? $a['map'] : [];
        $emp = self::field($rec, $map['emp'] ?? '');
        $dt = trim((string) self::field($rec, $map['datetime'] ?? ''));
        if ($dt === '' && ($map['date'] ?? '') !== '') {
            $dt = trim(self::field($rec, $map['date']).' '.self::field($rec, $map['time'] ?? ''));
        }
        if ($emp === null || trim((string) $emp) === '' || $dt === '') {
            return null;
        }
        $when = self::parseDate($dt, (string) ($a['punch_date_format'] ?? ''));
        if (! $when) {
            return null;
        }

        $machine = trim((string) self::field($rec, $map['machine'] ?? ''));
        $dirRaw = trim((string) self::field($rec, $map['direction'] ?? ''));
        // In/Out Machine ID override (parity with the other providers).
        if ($machine !== '' && $inMc !== '' && strcasecmp($machine, $inMc) === 0) {
            $direction = 'in';
        } elseif ($machine !== '' && $outMc !== '' && strcasecmp($machine, $outMc) === 0) {
            $direction = 'out';
        } else {
            $direction = self::mapDir($dirRaw, $a);
        }

        return [
            'emp_code' => trim((string) $emp),
            'name' => null,
            'punch_at' => $when,
            'direction' => $direction,
            'machine' => $machine,
        ];
    }

    /** Read one field from a record by key: numeric index, exact/ci key, or dot-path. */
    private static function field($rec, $key)
    {
        $key = trim((string) $key);
        if ($key === '' || ! is_array($rec)) {
            return null;
        }
        if (self::isList($rec) && is_numeric($key)) {
            return $rec[(int) $key] ?? null;
        }
        if (array_key_exists($key, $rec)) {
            return $rec[$key];
        }
        // case-insensitive flat key
        foreach ($rec as $k => $v) {
            if (strcasecmp((string) $k, $key) === 0) {
                return $v;
            }
        }
        if (strpos($key, '.') !== false) {
            return self::dotGet($rec, $key);
        }

        return null;
    }

    private static function mapDir(?string $v, array $a): string
    {
        $v = strtolower(trim((string) $v));
        $in = array_filter(array_map('trim', explode(',', strtolower((string) ($a['dir_in'] ?? '')))));
        $out = array_filter(array_map('trim', explode(',', strtolower((string) ($a['dir_out'] ?? '')))));
        if ($v !== '' && in_array($v, $out, true)) {
            return 'out';
        }
        if ($v !== '' && in_array($v, $in, true)) {
            return 'in';
        }
        if ($v !== '' && str_contains($v, 'out')) {
            return 'out';
        }

        return 'in';
    }

    private static function dotGet(array $data, string $path)
    {
        foreach (explode('.', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            if (is_array($data) && array_key_exists($seg, $data)) {
                $data = $data[$seg];
            } else {
                return null;
            }
        }

        return $data;
    }

    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    private static function unescape(string $s): string
    {
        return str_replace(['\\t', '\\n', '\\r'], ["\t", "\n", "\r"], $s);
    }

    private static function parseDate(string $v, string $fmt = ''): ?Carbon
    {
        $v = trim($v);
        if ($v === '' || ! preg_match('~\d~', $v)) {
            return null;
        }
        $fmt = trim($fmt);
        if ($fmt !== '') {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable $e) {
            }
        }
        foreach ([
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y/m/d H:i:s', 'Y/m/d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'm/d/Y H:i:s', 'm/d/Y H:i',
        ] as $f) {
            try {
                $d = Carbon::createFromFormat($f, $v);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
