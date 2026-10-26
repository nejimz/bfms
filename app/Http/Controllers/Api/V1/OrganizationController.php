<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->resource(Organization::current()),
        ]);
    }

    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $organization = Organization::current();
        $organization->name = $request->string('name')->toString();

        $logo = $request->file('logo');

        if ($logo instanceof UploadedFile) {
            $path = $logo->store('organization', 'public');

            if (! is_string($path)) {
                throw ValidationException::withMessages([
                    'logo' => 'The logo could not be saved.',
                ]);
            }

            $organization->deleteLogoFile();
            $organization->logo_path = $path;
        } elseif ($request->boolean('remove_logo')) {
            $organization->deleteLogoFile();
            $organization->logo_path = null;
        }

        $organization->save();

        return response()->json([
            'data' => $this->resource($organization),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Organization $organization): array
    {
        return [
            'id' => (string) $organization->id,
            'type' => 'organizations',
            'attributes' => [
                'name' => $organization->name,
                'logo_url' => $organization->logoUrl(),
            ],
        ];
    }
}
