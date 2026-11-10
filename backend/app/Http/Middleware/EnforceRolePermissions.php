<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the role matrix documented in Docs/01-PRD.md (section 3.9):
 *
 *  - admin     : full access.
 *  - manager   : manages assigned clients, leads, publishing, reports; cannot manage integrations or team.
 *  - executive : publishes drafts and follows up on leads; cannot create clients or edit integrations.
 *  - viewer    : read-only.
 *
 * Must run after an authentication middleware so that $request->user() is resolved.
 */
class EnforceRolePermissions
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Mutating endpoints that only an admin may call (team + integration/connection management). */
    private const ADMIN_ONLY = [
        'team',
        'team/*',
        'integrations',
        'integrations/disconnect',
        'integrations/manual-connect',
        'facebook/connect-page',
        'facebook/disconnect',
        'facebook/disconnect-page',
        'instagram/connect',
        'youtube/disconnect',
        'twitter/disconnect',
        'twitter/connect-mock',
        'google-analytics/configure',
        'google-analytics/select-property',
        'google-analytics/disconnect',
        'search-console/configure',
        'search-console/select-site',
    ];

    /** Mutating endpoints executives may not call but managers may (creating clients). */
    private const ADMIN_OR_MANAGER_ONLY = [
        'workspaces',
    ];

    /** Mutating endpoints that act on the caller's own session/notifications; allowed for every role. */
    private const ALL_ROLES = [
        'logout',
        'notifications/mark-read',
        'notifications/*/read',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $path = $this->relativePath($request);
        $role = $user->role ?: 'viewer';

        if ($this->matches($path, self::ALL_ROLES)) {
            return $next($request);
        }

        if ($role === 'viewer') {
            return $this->forbidden('Viewers have read-only access.');
        }

        $adminOnly = $this->matches($path, self::ADMIN_ONLY)
            || ($request->isMethod('DELETE') && $this->matches($path, ['integrations/*']));

        if ($adminOnly && $role !== 'admin') {
            return $this->forbidden('Only administrators can manage team members and integrations.');
        }

        if ($this->matches($path, self::ADMIN_OR_MANAGER_ONLY) && ! in_array($role, ['admin', 'manager'], true)) {
            return $this->forbidden('Your role is not allowed to create clients.');
        }

        return $next($request);
    }

    private function relativePath(Request $request): string
    {
        return trim(preg_replace('#^api/(v1/)?#', '', $request->path()), '/');
    }

    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function forbidden(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
