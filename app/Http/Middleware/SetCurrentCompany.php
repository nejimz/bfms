<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $selectedId = $request->hasSession()
            ? $request->session()->get('current_company_id')
            : null;

        $company = null;

        if ($selectedId !== null) {
            $company = $user->companies()->where('companies.id', $selectedId)->first();
        }

        $company ??= $user->companies()->orderBy('companies.id')->first();

        if ($company === null) {
            abort(403, 'You do not belong to a company.');
        }

        app(CurrentCompany::class)->set($company, (string) $company->pivot->role);

        app()->terminating(function () {
            app(CurrentCompany::class)->forget();
        });

        return $next($request);
    }
}
