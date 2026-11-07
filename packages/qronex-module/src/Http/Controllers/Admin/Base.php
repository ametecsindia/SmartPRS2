<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Qronex;
use Illuminate\Routing\Controller;

abstract class Base extends Controller
{
    protected function tid(): int
    {
        return Qronex::tenantId();
    }

    protected function uid(): ?int
    {
        return auth()->id();
    }
}
