<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Defense-in-depth guard: assert the authenticated user holds the given
     * permission, aborting with 403 otherwise. Route middleware is the primary
     * enforcement layer; this exists so a mis-configured route or a direct
     * controller invocation can never bypass a privileged action.
     */
    protected function authorizePermission(string $permission): void
    {
        abort_if(auth()->user()?->can($permission) !== true, 403);
    }
}
