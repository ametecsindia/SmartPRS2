<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * rev 132 — DYNAMIC WEB / PWA APP ICON.
 *
 * The home-screen icon (Add to Home Screen), the browser favicon and the iOS
 * apple-touch-icon are all served from here, built from the logged-in tenant's
 * uploaded Company Branding logo (centred on a clean white tile). When a client
 * adds the app to their phone, they get THEIR OWN logo as the app icon. Falls
 * back to the SmartPRS icon when there is no uploaded logo (or no session).
 *
 * NOTE: this is the web/PWA icon. A native Play Store / App Store launcher icon
 * cannot be changed from an uploaded image at runtime — that needs a per-client
 * app build (documented for Ejaz).
 */
class AppIconController extends Controller
{
    /** Effective branding for the logged-in user's company (null if no session). */
    private function brand(): ?array
    {
        try {
            $u = Auth::user();
            if (! $u) {
                return null;
            }
            $cid = $u->company_id ?? null;
            if (! $cid) {
                $cid = DB::table('companies')->where('tenant_id', $u->tenant_id)
                    ->whereNull('deleted_at')->orderByDesc('is_master')->value('id');
            }

            return $cid ? ConfigController::brandFor($u->tenant_id, $cid) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** GET /app-icon.png?s=192 — square PNG of the company logo (or the default). */
    public function icon(Request $request)
    {
        $size = (int) $request->query('s', 192);
        $size = max(48, min(512, $size));
        $brand = $this->brand();
        $logo = $brand['logo_file'] ?? null;

        if ($logo && is_file($logo) && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring(@file_get_contents($logo));
            if ($src) {
                $png = $this->squarePng($src, $size);
                @imagedestroy($src);
                if ($png !== null) {
                    return response($png, 200)
                        ->header('Content-Type', 'image/png')
                        ->header('Cache-Control', 'public, max-age=300');
                }
            }
        }

        // Fallback: the bundled SmartPRS icon.
        $def = public_path('images/logo-icon.png');
        if (is_file($def)) {
            return response()->file($def, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300']);
        }
        abort(404);
    }

    /** GET /apple-touch-icon.png — iOS home-screen icon (180px). */
    public function appleTouchIcon(Request $request)
    {
        $request->merge(['s' => 180]);

        return $this->icon($request);
    }

    /** GET /app.webmanifest — PWA manifest with the company name + dynamic icons. */
    public function manifest(Request $request)
    {
        $brand = $this->brand();
        $name = $brand['display_name'] ?? 'SmartPRS';
        $theme = $brand['color'] ?? '#0c1929';
        $manifest = [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => '/app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => $theme,
            'icons' => [
                ['src' => '/app-icon.png?s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/app-icon.png?s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];

        return response()->json($manifest)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** Centre $src on a white square of $size and return PNG binary (or null). */
    private function squarePng($src, int $size): ?string
    {
        try {
            $canvas = imagecreatetruecolor($size, $size);
            imagealphablending($canvas, true);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $white);

            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) {
                return null;
            }
            $pad = (int) round($size * 0.12);
            $maxw = $size - 2 * $pad;
            $maxh = $size - 2 * $pad;
            $scale = min($maxw / $sw, $maxh / $sh);
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));
            $dx = (int) (($size - $dw) / 2);
            $dy = (int) (($size - $dh) / 2);
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);

            ob_start();
            imagepng($canvas);
            $data = ob_get_clean();
            @imagedestroy($canvas);

            return $data ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
