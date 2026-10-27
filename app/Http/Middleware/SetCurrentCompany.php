<?php

namespace App\Http\Middleware;

use App\Models\Company;
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

        if (! $user->is_active) {
            abort(403, 'This account is inactive.');
        }

        $selectedId = $request->hasSession()
            ? $request->session()->get('current_company_id')
            : null;

        $company = null;

        if ($selectedId !== null) {
            $company = $user->accessibleCompanies()->where('companies.id', $selectedId)->first();
        }

        $company ??= $user->accessibleCompanies()->orderBy('companies.id')->first();

        if (! $company instanceof Company) {
            if ($user->isAdmin()) {
                return $next($request);
            }

            abort(403, 'You do not belong to a company.');
        }

        app(CurrentCompany::class)->set($company, $user->role->value);

        app()->terminating(function () {
            app(CurrentCompany::class)->forget();
        });

        return $next($request);
    }
}
