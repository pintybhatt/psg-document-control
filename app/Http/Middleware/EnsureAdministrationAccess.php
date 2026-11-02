<?php

namespace App\Http\Middleware;

use App\Authorization\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministrationAccess
{
    public function __construct(private AccessControl $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->canAccessAdministration($request->user()), 403, 'rbacForbidden');

        return $next($request);
    }
}
