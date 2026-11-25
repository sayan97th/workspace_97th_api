<?php

namespace App\Http\Controllers\HomeBanner;

use App\Http\Controllers\Controller;
use App\Http\Requests\HomeBanner\StoreHomeBannerRequest;
use App\Http\Requests\HomeBanner\UpdateHomeBannerRequest;
use App\Http\Resources\HomeBannerResource;
use App\Models\HomeBanner;
use App\Models\User;
use App\Services\HomeBanner\HomeBannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The background image of the welcome banner on Home, as the signed in person sees it.
 *
 * Home shows the person's own banner first, then the one an admin set for the organization, then
 * the built in gradient. Anyone can manage their own banner here, the organization banner is
 * managed from Administration through {@see OrganizationHomeBannerController}.
 */
class HomeBannerController extends Controller
{
    /** Roles allowed to change the organization banner, mirrors the Organization page. */
    public const ORGANIZATION_EDITOR_ROLES = ['super_admin', 'admin'];

    public function __construct(private readonly HomeBannerService $banners) {}

    /**
     * GET /api/home-banner
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->payload($user));
    }

    /**
     * POST /api/profile/home-banner
     */
    public function store(StoreHomeBannerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->banners->store($user, $request->file('file'), $request->framing(), $user, $request->sourceDetails());

        return response()->json([
            'message' => 'Your banner was updated.',
            ...$this->payload($user),
        ]);
    }

    /**
     * PATCH /api/profile/home-banner
     */
    public function update(UpdateHomeBannerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $banner = HomeBanner::personalBannerFor($user);
        abort_if($banner === null, 404, 'You have not uploaded a banner yet.');

        $this->banners->reframe($banner, $request->framing(), $user);

        return response()->json([
            'message' => 'Your banner was updated.',
            ...$this->payload($user),
        ]);
    }

    /**
     * DELETE /api/profile/home-banner
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $banner = HomeBanner::personalBannerFor($user);
        if ($banner) {
            $this->banners->destroy($banner);
        }

        return response()->json([
            'message' => 'Your banner was removed.',
            ...$this->payload($user),
        ]);
    }

    /**
     * Both banners and what the person may change, the frontend resolves which one is shown.
     *
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        $personal = HomeBanner::personalBannerFor($user)?->load('editor');
        $organization = HomeBanner::organizationBanner()?->load('editor');

        return [
            'personal' => $personal ? new HomeBannerResource($personal) : null,
            'organization' => $organization ? new HomeBannerResource($organization) : null,
            'can_manage_organization' => (bool) $user->hasRole(self::ORGANIZATION_EDITOR_ROLES),
        ];
    }
}
