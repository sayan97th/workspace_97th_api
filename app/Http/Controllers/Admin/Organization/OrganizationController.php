<?php

namespace App\Http\Controllers\Admin\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\AccountSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Administration > Organization (`/admin/organization`): the company profile, contact and
 * support details, brand color, sign in page copy and the announcement banner. Uploaded
 * images go through {@see OrganizationAssetController}.
 */
class OrganizationController extends Controller
{
    /**
     * Fields that, once changed, make a banner people already dismissed show up again.
     */
    private const ANNOUNCEMENT_CONTENT_FIELDS = [
        'announcement_enabled', 'announcement_message', 'announcement_tone',
        'announcement_link_label', 'announcement_link_url',
    ];

    /**
     * GET /api/admin/organization
     */
    public function show(): JsonResponse
    {
        return response()->json(new OrganizationResource(AccountSetting::current()));
    }

    /**
     * PATCH /api/admin/organization
     */
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $validated = $request->validated();

        if (array_key_exists('company_name', $validated)) {
            $validated['account_name'] = $validated['company_name'];
            unset($validated['company_name']);
        }

        if (array_key_exists('social_links', $validated)) {
            $filled_links = array_filter($validated['social_links'] ?? [], fn (?string $link) => filled($link));
            $validated['social_links'] = $filled_links === [] ? null : $filled_links;
        }

        $settings->fill($validated);

        if ($settings->announcement_enabled && $settings->isDirty(self::ANNOUNCEMENT_CONTENT_FIELDS)) {
            $settings->announcement_published_at = now();
        }

        $changed_fields = array_keys($settings->getDirty());
        $settings->save();

        if ($changed_fields !== []) {
            AuditLogger::log(
                'organization.updated',
                'Updated the organization settings.',
                $request->user(),
                ['fields' => $changed_fields],
            );
        }

        return response()->json([
            'message' => 'Organization settings updated successfully.',
            'organization' => new OrganizationResource($settings),
        ]);
    }
}
