<?php

namespace App\Services;

use App\Models\FacebookPage;
use App\Models\Integration;
use Illuminate\Validation\ValidationException;

class WorkspaceSocialAccounts
{
    public function instagram(int $workspaceId): ?Integration
    {
        $integration = $this->integration($workspaceId, 'instagram');
        if ($integration) {
            return $integration;
        }

        // If an explicit Instagram integration record exists for this workspace (even if disconnected),
        // do not auto-link or resurrect it from Facebook.
        if (Integration::where('workspace_id', $workspaceId)->where('platform', 'instagram')->exists()) {
            return null;
        }

        // If no explicit Instagram integration, check if the workspace has an active Facebook page with a linked Instagram business account
        $fbPage = FacebookPage::where('workspace_id', $workspaceId)
            ->whereNotNull('page_access_token')
            ->where(function ($q) {
                $q->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])->orWhereNull('token_status');
            })
            ->first();

        if ($fbPage && !empty($fbPage->page_access_token)) {
            try {
                $graphService = app(FacebookGraphService::class);
                $igId = $graphService->resolveInstagramAccountId($fbPage->page_access_token, $fbPage->page_id);
                if (!empty($igId) && $igId !== 'me') {
                    $profile = $graphService->getInstagramProfile($igId, $fbPage->page_access_token);
                    $igUsername = $profile['username'] ?? null;
                    $accountName = $igUsername ? (str_starts_with($igUsername, '@') ? $igUsername : "@{$igUsername}") : ('@' . ltrim($fbPage->page_name, '@'));

                    return Integration::updateOrCreate(
                        [
                            'workspace_id' => $workspaceId,
                            'platform'     => 'instagram',
                            'account_id'   => $igId,
                        ],
                        [
                            'account_name'      => $accountName,
                            'refresh_token'     => $fbPage->page_access_token,
                            'is_connected'      => true,
                            'connection_status' => 'connected',
                            'last_sync_at'      => now(),
                        ]
                    );
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[WorkspaceSocialAccounts] Failed to auto-resolve linked Instagram: ' . $e->getMessage());
            }
        }

        return null;
    }

    public function facebook(int $workspaceId): ?FacebookPage
    {
        $integration = $this->integration($workspaceId, 'facebook');
        $query = FacebookPage::where('workspace_id', $workspaceId)
            ->whereNotNull('page_access_token')
            ->where(function ($q) {
                $q->whereNotIn('token_status', ['invalid', 'disconnected', 'revoked'])->orWhereNull('token_status');
            });

        // An explicit integration controls selection even if historical Page
        // records have newer sync timestamps.
        if ($integration) {
            return $query->where('page_id', $integration->account_id)->first();
        }
        if (Integration::where('workspace_id', $workspaceId)->where('platform', 'facebook')->exists()) {
            return null;
        }
        $pages = $query->orderByDesc('updated_at')->get();
        return $pages->first();
    }

    private function integration(int $workspaceId, string $platform): ?Integration
    {
        $accounts = Integration::where('workspace_id', $workspaceId)
            ->where('platform', $platform)
            ->where('is_connected', true)
            ->where(function ($q) {
                $q->where('connection_status', '!=', 'disconnected')->orWhereNull('connection_status');
            })
            ->orderByDesc('updated_at')
            ->get();
        return $accounts->first();
    }
}
