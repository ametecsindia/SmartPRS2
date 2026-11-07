<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

/** Serves the module's CSS/JS straight from the package, so the host needs no asset publishing. */
class AssetController
{
    public function __invoke(\Illuminate\Http\Request $request)
    {
        $file = (string) $request->query('f');
        abort_unless(preg_match('/^[a-z0-9-]+(\.min)?\.(css|js)$/', $file), 404);
        $path = realpath(__DIR__.'/../../../../resources/assets/'.$file);
        abort_unless($path && str_starts_with($path, realpath(__DIR__.'/../../../../resources/assets')), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.css') ? 'text/css' : 'application/javascript',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
