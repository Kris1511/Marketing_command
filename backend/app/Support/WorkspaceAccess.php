<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single place that decides whether a user may touch a client workspace's publishing data.
 *
 *  - admin : every workspace ("All Workspaces" is an admin-only reading concept).
 *  - others: only workspaces they own or are assigned to through user_workspace_roles.
 */
class WorkspaceAccess
{
    public static function canAccess(?User $user, int $workspaceId): bool
    {
        if (! $user || $workspaceId <= 0) {
            return false;
        }

        if ($user->role === 'admin') {
            return Workspace::whereKey($workspaceId)->exists();
        }

        return Workspace::whereKey($workspaceId)
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                  ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
            })
            ->exists();
    }

    /** Ids the user may access, or null when every workspace is allowed (admin). */
    public static function accessibleIds(?User $user): ?array
    {
        if (! $user) {
            return [];
        }
        if ($user->role === 'admin') {
            return null;
        }

        return Workspace::query()
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                  ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Returns null when the request may proceed, or a ready-made JSON error (422/404/403) otherwise.
     * "all" and non-numeric values are rejected because publishing always targets exactly one client.
     */
    public static function guard(Request $request, mixed $workspaceId, ?User $user = null): ?JsonResponse
    {
        if (is_string($workspaceId) && strtolower(trim($workspaceId)) === 'all') {
            return response()->json([
                'success' => false,
                'message' => 'Please select an individual client workspace. "All Workspaces" cannot be used for publishing.',
                'errors'  => ['workspace_id' => ['Select an individual client workspace.']],
            ], 422);
        }

        if ($workspaceId === null || $workspaceId === '' || ! is_numeric($workspaceId) || (int) $workspaceId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'A valid workspace_id is required.',
                'errors'  => ['workspace_id' => ['A valid workspace is required.']],
            ], 422);
        }

        $id = (int) $workspaceId;
        if (! Workspace::whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Workspace not found.'], 404);
        }

        if (! self::canAccess($user ?? $request->user(), $id)) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this workspace.'], 403);
        }

        return null;
    }
}
