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

    /**
     * Raise the memory ceiling before rendering a PDF. Shared hosting often caps
     * PHP memory below what dompdf needs to lay out + render a large document,
     * which surfaces as a 500. Bumping the limit here is safe: under normal
     * conditions it never approaches the ceiling.
     */
    protected function preparePdf(): void
    {
        $limit = '256M';
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 256) {
            @ini_set('memory_limit', $limit);
        }
    }
}
