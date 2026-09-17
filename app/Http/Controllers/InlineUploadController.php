<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generic image upload for content pasted or dropped directly into a rich
 * text editor (comment composer, Update Feed composer). These images are
 * part of the message body itself, not a comment "attachment" — they are
 * stored outside the attachments relation so the Files tab never sees them.
 */
class InlineUploadController extends Controller
{
    /**
     * POST /api/uploads/inline-images
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240'],
        ]);

        $file = $validated['image'];
        $extension = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            'inline-images/'.now()->format('Y/m'),
            Str::uuid().'.'.$extension,
            config('filesystems.app_disk')
        );

        return response()->json([
            'data' => [
                'url' => Storage::disk(config('filesystems.app_disk'))->url($path),
            ],
        ], 201);
    }
}
