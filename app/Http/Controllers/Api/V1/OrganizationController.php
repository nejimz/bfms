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

        $this->applyUpload($organization, 'logo_path', 'logo', $request->file('logo'), $request->boolean('remove_logo'), 'The logo could not be saved.');
        $this->applyUpload($organization, 'icon_path', 'icon', $request->file('icon'), $request->boolean('remove_icon'), 'The icon could not be saved.');
        $this->applyUpload($organization, 'banner_path', 'banner', $request->file('banner'), $request->boolean('remove_banner'), 'The banner could not be saved.');

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
                'icon_url' => $organization->iconUrl(),
                'banner_url' => $organization->bannerUrl(),
            ],
        ];
    }

    private function applyUpload(
        Organization $organization,
        string $column,
        string $field,
        mixed $file,
        bool $remove,
        string $failureMessage,
    ): void {
        if ($file instanceof UploadedFile) {
            $path = $file->store('organization', 'public');

            if (! is_string($path)) {
                throw ValidationException::withMessages([
                    $field => $failureMessage,
                ]);
            }

            $current = $organization->getAttribute($column);
            $organization->deleteFile(is_string($current) ? $current : null);
            $organization->setAttribute($column, $path);

            return;
        }

        if ($remove) {
            $current = $organization->getAttribute($column);
            $organization->deleteFile(is_string($current) ? $current : null);
            $organization->setAttribute($column, null);
        }
    }
}
