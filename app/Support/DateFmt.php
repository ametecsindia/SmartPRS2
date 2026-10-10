<?php

namespace App\Support;

/**
 * 7 Oct 2026 (Ejaz) — ONE date standard for SmartPRS: DD-MM-YYYY on screen,
 * in samples, exports, PDFs and mails. Storage stays ISO (Y-m-d) everywhere.
 *
 *   toIso('05-06-2026')         → '2026-06-05'      (input side: forms, imports)
 *   display('2026-06-05 09:15') → '05-06-2026 09:15' (output side)
 *   text('From 2026-06-05 …')   → 'From 05-06-2026 …' (dates inside free text)
 */
class DateFmt
{
    /** dd-mm-yyyy / dd/mm/yyyy / dd.mm.yyyy [HH:MM[:SS]] → ISO. Anything else is returned untouched. */
    public static function toIso($v)
    {
        if (! is_string($v) || strlen($v) < 8 || strlen($v) > 22) {
            return $v;
        }
        if (! preg_match('#^\s*(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})(?:[ T](\d{1,2}):(\d{2})(:\d{2})?)?\s*$#', $v, $m)) {
            return $v;
        }
        if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return $v;
        }
        $out = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        if (($m[4] ?? '') !== '') {
            $out .= sprintf(' %02d:%s%s', $m[4], $m[5], $m[6] ?? '');
        }

        return $out;
    }

    /** ISO date / datetime (string or DateTimeInterface) → dd-mm-yyyy [HH:MM[:SS]]. Non-dates pass through. */
    public static function display($v)
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('H:i:s') === '00:00:00' ? $v->format('d-m-Y') : $v->format('d-m-Y H:i');
        }
        if (! is_string($v) || ! preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?/', $v, $m)) {
            return $v;
        }

        return $m[3].'-'.$m[2].'-'.$m[1].(isset($m[4]) ? ' '.$m[4] : '');
    }

    /** Rewrite every ISO date inside free text (mail bodies, CSV cells, messages). */
    public static function text($s)
    {
        if (! is_string($s) || $s === '') {
            return $s;
        }

        return preg_replace_callback('/(?<![\w\/-])((?:19|20)\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])(?![\w\/-])/',
            fn ($m) => $m[3].'-'.$m[2].'-'.$m[1], $s);
    }

    /** CSV row helper: rewrite ISO dates in each cell. */
    public static function row(array $cells): array
    {
        return array_map(fn ($c) => is_string($c) ? self::text($c) : $c, $cells);
    }

    public static function selfTest(): void
    {
        assert(self::toIso('05-06-2026') === '2026-06-05');
        assert(self::toIso('5/6/2026 9:15') === '2026-06-05 09:15');
        assert(self::toIso('31-02-2026') === '31-02-2026');
        assert(self::toIso('EMP-12-2026') === 'EMP-12-2026');
        assert(self::toIso('2026-06-05') === '2026-06-05');
        assert(self::display('2026-06-05 09:15:00') === '05-06-2026 09:15:00');
        assert(self::display('June') === 'June');
        assert(self::text('Leave 2026-06-05 to 2026-06-07, ref INV-2026-06-05') === 'Leave 05-06-2026 to 07-06-2026, ref INV-2026-06-05');
    }
}
