<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\Announcement;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AnnouncementController extends Controller
{
    /** GET /admin/announcements — list all */
    public function index()
    {
        $announcements = Announcement::latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $announcements,
        ]);
    }

    /** POST /admin/announcements — create */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'     => 'required|string|max:255',
            'message'   => 'required|string',
            'type'      => 'required|in:info,warning,success,error',
            'is_active' => 'boolean',
        ]);

        $announcement = Announcement::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Announcement created successfully',
            'data'    => $announcement,
        ], 201);
    }

    /** PATCH /admin/announcements/{id} — update */
    public function update(Request $request, $id)
    {
        $announcement = Announcement::findOrFail($id);

        $validated = $request->validate([
            'title'     => 'sometimes|string|max:255',
            'message'   => 'sometimes|string',
            'type'      => 'sometimes|in:info,warning,success,error',
            'is_active' => 'sometimes|boolean',
        ]);

        $announcement->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Announcement updated',
            'data'    => $announcement,
        ]);
    }

    /** DELETE /admin/announcements/{id} */
    public function destroy($id)
    {
        Announcement::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Announcement deleted',
        ]);
    }

    /** GET /announcements — fetch active ones for users */
    public function active()
    {
        $announcements = Announcement::where('is_active', true)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $announcements,
        ]);
    }
}
