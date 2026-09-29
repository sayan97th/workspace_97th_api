<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountAutomationTemplateResource;
use App\Models\AccountAutomationTemplate;
use App\Models\BoardAutomation;
use App\Support\PortableAutomationDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The account wide automation templates of the Create tab's "Created by" category. Everyone can
 * list and use them, only administrators publish and delete them (see the route group).
 */
class AccountAutomationTemplateController extends Controller
{
    /**
     * GET /api/automation-templates
     */
    public function index(): JsonResponse
    {
        $templates = AccountAutomationTemplate::with('creator')->latest('id')->get();

        return response()->json(['data' => AccountAutomationTemplateResource::collection($templates)]);
    }

    /**
     * POST /api/automation-templates
     *
     * Publishes one board automation for every board, see {@see PortableAutomationDefinition}.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'automation_id' => ['required', 'integer', Rule::exists('board_automations', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $automation = BoardAutomation::findOrFail($validated['automation_id']);
        $portable = PortableAutomationDefinition::fromAutomation($automation);

        $template = AccountAutomationTemplate::create([
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? $automation->description,
            'definition' => $portable['definition'],
            'column_kinds' => $portable['column_kinds'],
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Template published for every board.',
            'template' => new AccountAutomationTemplateResource($template->load('creator')),
        ], 201);
    }

    /**
     * DELETE /api/automation-templates/{template}
     */
    public function destroy(AccountAutomationTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['message' => 'Template deleted successfully.']);
    }
}
