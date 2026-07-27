<?php

namespace App\Http\Controllers;

use App\Services\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * rev 112: Super-admin coupon management — /admin/coupons.
 * Create/edit/disable discount coupons + see every redemption (which
 * campaign brought which client). CouponService holds validation/apply logic.
 */
class CouponController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    public function index(Request $request)
    {
        $this->guard($request);
        CouponService::ensure();
        $coupons = DB::table('coupons')->orderByDesc('id')->get();
        $redemptions = DB::table('coupon_redemptions')->orderByDesc('id')->limit(100)->get();
        $plans = DB::table('plans')->where('status', 'active')->orderBy('id')->get(['id', 'name']);

        return view('admin.coupons', [
            'coupons' => $coupons, 'redemptions' => $redemptions, 'plans' => $plans,
        ]);
    }

    public function save(Request $request)
    {
        $this->guard($request);
        CouponService::ensure();
        $v = $request->validate([
            'id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'type' => ['required', 'in:percent,flat'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'valid_till' => ['nullable', 'date'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'min_cycle' => ['nullable', 'in:quarterly,halfyear,annual'],
            'plan_ids' => ['nullable', 'array'],
            'applies_to' => ['required', 'in:signup,renewal,both'],
            'once_per_customer' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'code.regex' => 'Coupon code: letters, numbers, dash and underscore only (no spaces).',
        ]);
        if ($v['type'] === 'percent' && (float) $v['value'] > 100) {
            return back()->with('success', '')->withErrors(['value' => 'A percentage discount cannot exceed 100.']);
        }
        $code = strtoupper(trim($v['code']));
        $dupe = DB::table('coupons')->where('code', $code)->when(! empty($v['id']), fn ($q) => $q->where('id', '<>', (int) $v['id']))->exists();
        if ($dupe) {
            return back()->withErrors(['code' => 'That coupon code already exists.']);
        }
        $row = [
            'code' => $code,
            'type' => $v['type'],
            'value' => round((float) $v['value'], 2),
            'valid_till' => $v['valid_till'] ?: null,
            'max_uses' => $v['max_uses'] ?: null,
            'min_cycle' => $v['min_cycle'] ?: null,
            'plan_ids' => ! empty($v['plan_ids']) ? implode(',', array_map('intval', $v['plan_ids'])) : null,
            'applies_to' => $v['applies_to'],
            'once_per_customer' => $request->boolean('once_per_customer') ? 1 : 0,
            'notes' => $v['notes'] ?? null,
            'updated_at' => now(),
        ];
        if (! empty($v['id'])) {
            DB::table('coupons')->where('id', (int) $v['id'])->update($row);
            $msg = 'Coupon '.$code.' updated.';
        } else {
            $row += ['status' => 'active', 'used_count' => 0, 'created_at' => now()];
            DB::table('coupons')->insert($row);
            $msg = 'Coupon '.$code.' created — share it in your campaign.';
        }

        return redirect()->route('admin.coupons')->with('success', $msg);
    }

    /**
     * rev 113: SEND AN EXCLUSIVE OFFER BY EMAIL — creates a single-use coupon
     * locked to one email address and emails it. The cart auto-applies it the
     * moment that email is typed at signup/renewal/quotation.
     */
    public function sendExclusive(Request $request)
    {
        $this->guard($request);
        CouponService::ensure();
        $v = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'in:percent,flat'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'valid_days' => ['required', 'integer', 'min:1', 'max:365'],
            'applies_to' => ['required', 'in:signup,renewal,both'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        if ($v['type'] === 'percent' && (float) $v['value'] > 100) {
            return back()->withErrors(['value' => 'A percentage discount cannot exceed 100.']);
        }
        $email = strtolower(trim($v['email']));
        // Unambiguous auto code: EXC-XXXXXX (no 0/O/1/I).
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = 'EXC-'.substr(str_shuffle(str_repeat($chars, 3)), 0, 6);
        } while (DB::table('coupons')->where('code', $code)->exists());
        $validTill = now()->addDays((int) $v['valid_days'])->toDateString();
        DB::table('coupons')->insert([
            'code' => $code, 'type' => $v['type'], 'value' => round((float) $v['value'], 2),
            'valid_till' => $validTill, 'max_uses' => 1, 'used_count' => 0,
            'min_cycle' => null, 'plan_ids' => null, 'once_per_customer' => 1,
            'applies_to' => $v['applies_to'], 'exclusive_email' => $email,
            'status' => 'active', 'notes' => trim('Exclusive: '.($v['notes'] ?? '')),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $label = $v['type'] === 'flat' ? '₹'.number_format((float) $v['value']).' off' : rtrim(rtrim(number_format((float) $v['value'], 2), '0'), '.').'% off';
        try {
            \App\Services\MailService::queue([
                'tenant_id' => null,
                'kind' => 'coupon.exclusive',
                'to' => $email,
                'subject' => 'An exclusive SmartPRS offer for you — '.$label,
                'heading' => 'An exclusive offer, just for you',
                'intro' => ($v['name'] ? 'Dear '.$v['name'].', ' : '').'we have reserved a personal discount on SmartPRS for this email address: '.$label.', valid till '.\Carbon\Carbon::parse($validTill)->format('d M Y').'.',
                'lines' => [
                    'Your exclusive code: '.$code,
                    'It is linked to this email — simply use '.$email.' on the page and the discount applies by itself.',
                    'Valid for one purchase, till '.\Carbon\Carbon::parse($validTill)->format('d M Y').'.',
                ],
                'cta_label' => 'Claim your offer',
                'cta_url' => url('/signup'),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('exclusive coupon mail: '.$e->getMessage());
        }

        return redirect()->route('admin.coupons')->with('success', 'Exclusive offer '.$code.' ('.$label.') created for '.$email.' and emailed.');
    }

    /** Toggle active/disabled. Disabling never affects already-completed redemptions. */
    public function toggle(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('coupons')->where('id', $id)->first();
        abort_unless($c, 404);
        DB::table('coupons')->where('id', $id)->update([
            'status' => $c->status === 'active' ? 'disabled' : 'active', 'updated_at' => now(),
        ]);

        return redirect()->route('admin.coupons')->with('success', 'Coupon '.$c->code.' is now '.($c->status === 'active' ? 'DISABLED' : 'ACTIVE').'.');
    }

    /** Hard delete — allowed only for coupons never redeemed (audit trail). */
    public function destroy(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('coupons')->where('id', $id)->first();
        abort_unless($c, 404);
        if (DB::table('coupon_redemptions')->where('coupon_id', $id)->exists()) {
            // Audit trail: a redeemed coupon is never hard-deleted — disable it.
            DB::table('coupons')->where('id', $id)->update(['status' => 'disabled', 'updated_at' => now()]);

            return redirect()->route('admin.coupons')->with('success', 'Coupon '.$c->code.' has redemptions — it was DISABLED instead of deleted (audit trail kept).');
        }
        DB::table('coupons')->where('id', $id)->delete();

        return redirect()->route('admin.coupons')->with('success', 'Coupon '.$c->code.' deleted.');
    }
}
