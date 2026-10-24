<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = app(CurrentCompany::class)->company();
        $companies = $user->accessibleCompanies()
            ->orderBy('companies.name')
            ->get()
            ->map(fn ($membership) => [
                'id' => (string) $membership->id,
                'name' => $membership->name,
                'description' => $membership->description,
                'currency' => $membership->currency,
            ])
            ->values();

        return response()->json([
            'data' => [
                'id' => (string) $user->id,
                'type' => 'users',
                'attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'company' => [
                        'id' => (string) $company->id,
                        'name' => $company->name,
                        'description' => $company->description,
                        'currency' => $company->currency,
                    ],
                    'companies' => $companies,
                ],
            ],
        ]);
    }
}
