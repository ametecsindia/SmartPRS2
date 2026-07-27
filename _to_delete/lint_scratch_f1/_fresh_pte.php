<?php

class PT
{
    private const PT_FREE_STATES = ['delhi', 'uttar pradesh', 'haryana', 'rajasthan', 'himachal', 'uttarakhand', 'chandigarh', 'jammu', 'ladakh', 'arunachal'];

    private const PT_STATE_SLABS = [
        'telangana' => [[15000, 0], [20000, 150], [0, 200]],
        'andhra' => [[15000, 0], [20000, 150], [0, 200]],
        'maharashtra' => [[7500, 0], [10000, 175], [0, 200]],   // + ₹300 in February (handled below)
        'karnataka' => [[24999, 0], [0, 200]],
        'west bengal' => [[10000, 0], [15000, 110], [25000, 130], [40000, 150], [0, 200]],
        'tamil nadu' => [[3500, 0], [5000, 23], [7500, 53], [10000, 115], [12500, 171], [0, 208]],  // half-yearly slabs shown as monthly equivalents
        'gujarat' => [[12000, 0], [0, 200]],
        'madhya pradesh' => [[18750, 0], [25000, 125], [33333, 167], [0, 208]],  // annual ₹2,500 cap spread monthly
        'kerala' => [[1999, 0], [2999, 20], [4999, 30], [7499, 50], [9999, 75], [12499, 100], [16666, 125], [20833, 166], [0, 208]],  // half-yearly slabs as monthly equivalents
        'bihar' => [[25000, 0], [41666, 83], [83333, 167], [0, 208]],
        'odisha' => [[13304, 0], [25000, 125], [0, 200]],
        'assam' => [[10000, 0], [15000, 150], [25000, 180], [0, 208]],
        'jharkhand' => [[25000, 0], [41666, 100], [66666, 150], [83333, 175], [0, 208]],
        'chhattisgarh' => [[13333, 0], [16666, 150], [20833, 180], [25000, 190], [0, 200]],
        'punjab' => [[20833, 0], [0, 200]],   // PSDT — ₹200/month for income-tax-payers
        'goa' => [[15000, 0], [25000, 150], [0, 200]],
    ];

    /**
     * The PT tables compiled into the engine, exposed read-only so the Statutory
     * Configuration screen can show what it is about to override instead of
     * duplicating the figures in a second place that then drifts.
     */
    public static function ptStateSlabs(): array
    {
        return self::PT_STATE_SLABS;
    }

    public static function ptFreeStates(): array
    {
        return self::PT_FREE_STATES;
    }

    /**
     * Walk one state's PT bands and return the monthly figure. Bands are
     * [gross upto, amount] with an "upto" of 0 meaning "everything above";
     * the first band the gross falls into wins.
     *
     * Maharashtra collects Rs 300 from the top band in February so the year
     * totals Rs 2,500 (11 x 200 + 300). That rule keys on the state, so it
     * applies to a configured Maharashtra slab exactly as to the built-in one.
     */
    private static function ptWalkSlab(array $rows, float $gross, string $key, ?string $month): float
    {
        $amt = 0.0;
        foreach ($rows as $row) {
            $row = is_array($row) ? array_values($row) : null;
            if (! $row || count($row) < 2) {
                continue;
            }
            $upto = (float) $row[0];
            if ($upto <= 0 || $gross <= $upto) {
                $amt = (float) $row[1];
                break;
            }
        }
        if ($key === 'maharashtra' && $amt >= 200 && $month && substr($month, 5, 2) === '02') {
            $amt = 300.0;
        }

        return round($amt, 2);
    }

    /**
     * Professional Tax — statutory MONTHLY slab on gross (not a flat amount).
     * rev180: STATE-AWARE. Resolution order:
     *   1. Employee's pt_state matches a built-in state table (incl. PT-free states → ₹0;
     *      Maharashtra February = ₹300 for the top slab).
     *   2. No/unknown state → the tenant's pt_slabs override, else the Telangana default.
     */
    public static function ptForGross(float $gross, array $r, ?string $state = null, ?string $month = null, ?string $gender = null): float
    {
        $st = strtolower(trim((string) $state));
        // Gender-based PT exemption. Under the Maharashtra State Tax on Professions
        // Act, women drawing a monthly gross up to ₹25,000 are exempt (PT = ₹0);
        // above that the normal slab applies. Configurable via pt_female_exempt
        // (default ON) and pt_female_exempt_upto (default ₹25,000). Applied BEFORE
        // the slab lookup so it overrides the state slab for eligible employees.
        $femaleExempt = ! array_key_exists('pt_female_exempt', $r) || (int) ($r['pt_female_exempt'] ?? 1) === 1;
        if ($femaleExempt) {
            $g = strtolower(trim((string) $gender));
            $isFemale = in_array($g, ['female', 'f', 'woman', 'women'], true);
            $femUpto = (float) ($r['pt_female_exempt_upto'] ?? 25000);
            if ($isFemale && $st !== '' && str_contains($st, 'maharashtra') && $gross <= $femUpto) {
                return 0.0;
            }
        }
        if ($st !== '') {
            // F1 — CONFIGURABLE STATE SLABS. `pt_state_slabs` (a map of
            // state => [[gross upto, monthly PT], ...]) lets HR correct a slab a
            // state has changed, or add a state that is not compiled in, without
            // a code release. An explicit slab for a state is an INSTRUCTION, so
            // it outranks the built-in PT-free list; that is how a state which
            // starts levying PT gets switched on. With no config present the
            // order below is exactly the original: free states, then built-ins.
            $custom = [];
            if (! empty($r['pt_state_slabs']) && is_array($r['pt_state_slabs'])) {
                foreach ($r['pt_state_slabs'] as $k => $rows) {
                    $k = mb_strtolower(trim((string) $k));
                    if ($k !== '' && is_array($rows) && $rows) {
                        $custom[$k] = $rows;
                    }
                }
            }
            foreach ($custom as $key => $rows) {
                if (str_contains($st, $key)) {
                    return self::ptWalkSlab($rows, $gross, $key, $month);
                }
            }

            $freeStates = self::PT_FREE_STATES;
            if (! empty($r['pt_free_states']) && is_array($r['pt_free_states'])) {
                foreach ($r['pt_free_states'] as $extra) {
                    $extra = mb_strtolower(trim((string) $extra));
                    if ($extra !== '') {
                        $freeStates[] = $extra;
                    }
                }
            }
            foreach ($freeStates as $free) {
                if (str_contains($st, $free)) {
                    return 0.0;
                }
            }

            foreach (self::PT_STATE_SLABS as $key => $rows) {
                if (str_contains($st, $key)) {
                    return self::ptWalkSlab($rows, $gross, $key, $month);
                }
            }
        }
        $slabs = $r['pt_slabs'] ?? null;
        if (! is_array($slabs) || ! $slabs) {
            $slabs = [
                ['upto' => 15000.0, 'amt' => 0.0],
                ['upto' => 20000.0, 'amt' => 150.0],
                ['upto' => PHP_FLOAT_MAX, 'amt' => 200.0],
            ];
        }
        foreach ($slabs as $s) {
            if ($gross <= (float) ($s['upto'] ?? PHP_FLOAT_MAX)) {
                return round((float) ($s['amt'] ?? 0), 2);
            }
        }
        return 0.0;
    }

}
