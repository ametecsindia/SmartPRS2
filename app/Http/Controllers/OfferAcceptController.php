<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public offer-acceptance flow (rev 48f). A candidate receives a tokenised link
 * (LetterController::sendAcceptLink), opens this PUBLIC page (no login), reviews
 * the offer, and confirms acceptance — which stamps the letter accepted + time + IP
 * and moves the recruitment candidate to "offer". No auth (token is the secret).
 */
class OfferAcceptController extends Controller
{
    private function ensureCols(): void
    {
        if (! Schema::hasTable('letters')) {
            return;
        }
        foreach (['accept_token' => 'string', 'accepted_at' => 'ts', 'accepted_ip' => 'string'] as $c => $t) {
            if (! Schema::hasColumn('letters', $c)) {
                Schema::table('letters', function (Blueprint $b) use ($c, $t) {
                    $t === 'ts' ? $b->timestamp($c)->nullable() : $b->string($c)->nullable();
                });
            }
        }
    }

    private function find(string $token)
    {
        $this->ensureCols();

        return DB::table('letters')->where('accept_token', $token)->where('is_template', 0)->first();
    }

    public function show(string $token)
    {
        $letter = $this->find($token);
        if (! $letter) {
            abort(404, 'This offer link is invalid or has expired.');
        }
        $company = DB::table('companies')->where('id', $letter->company_id)->value('name');
        $cand = DB::table('recruitment')->where('name', $letter->candidate)->first();
        $brand = [];
        try {
            $brand = ConfigController::brandFor($letter->tenant_id, $letter->company_id);
        } catch (\Throwable $e) {
            $brand = [];
        }

        return view('offer-accept', [
            'letter' => $letter,
            'company' => $company,
            'cand' => $cand,
            'brand' => $brand,
            'token' => $token,
            'accepted' => ($letter->status ?? '') === 'accepted',
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $letter = $this->find($token);
        if (! $letter) {
            abort(404);
        }
        if (($letter->status ?? '') !== 'accepted') {
            DB::table('letters')->where('id', $letter->id)->update([
                'status' => 'accepted', 'accepted_at' => now(), 'accepted_ip' => $request->ip(), 'updated_at' => now(),
            ]);
            // The candidate has accepted — flag it on the recruitment record and keep
            // them at the Offer stage. HR then confirms the hire from the pipeline
            // (no employee is created automatically from a public click).
            if ($letter->candidate && Schema::hasTable('recruitment')) {
                $upd = ['stage' => 'offer', 'updated_at' => now()];
                if (Schema::hasColumn('recruitment', 'offer_status')) {
                    $upd['offer_status'] = 'accepted';
                }
                DB::table('recruitment')->where('name', $letter->candidate)
                    ->where('tenant_id', $letter->tenant_id)->update($upd);
            }
        }

        return redirect()->route('offer.show', $token)->with('justAccepted', true);
    }
}
