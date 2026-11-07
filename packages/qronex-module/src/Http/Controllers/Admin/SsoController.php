<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * For hosts whose console signs in with API tokens (EPT): the host's authenticated API returns link(), a signed URL valid 2 minutes;
 * opening it starts a normal web session for that same user and lands in the module.
 */
class SsoController
{
    public static function link(int $userId): string
    {
        return URL::temporarySignedRoute('qronex.sso', now()->addMinutes(2), ['u' => $userId, 'n' => \Illuminate\Support\Str::random(16)]);
    }

    public function consume(Request $request)
    {
        abort_unless(cache()->add('qronex_sso:'.$request->query('n'), 1, 300), 403, 'This sign-in link was already used.');
        Auth::guard('web')->loginUsingId((int) $request->query('u'));
        $request->session()->regenerate();

        return redirect()->route('qronex.dashboard');
    }
}
