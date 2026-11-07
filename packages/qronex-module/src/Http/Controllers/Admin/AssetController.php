<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

/** Serves the module's CSS/JS straight from the package, so the host needs no asset publishing. */
class AssetController
{
    public function __invoke(string $file)
    {
        $path = realpath(__DIR__.'/../../../../resources/assets/'.$file);
        abort_unless($path && str_starts_with($path, realpath(__DIR__.'/../../../../resources/assets')), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.css') ? 'text/css' : 'application/javascript',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
