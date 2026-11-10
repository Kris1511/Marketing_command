<?php

namespace App\Services;

use App\Models\FacebookDataDeletionRequest;
use App\Models\FacebookPage;
use App\Models\Integration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles Meta's "Data Deletion Request Callback".
 *
 * Meta POSTs a `signed_request` (base64url(signature) . "." . base64url(payload)) whose signature is
 * HMAC-SHA256(payload_part, APP_SECRET). The payload carries the app-scoped Facebook `user_id`.
 * This application runs more than one Meta app, so the signature is checked against every configured
 * app secret and the app that signed it decides whose data is in scope.
 */
class FacebookDataDeletionService
{
    /**
     * @return list<array{app_id: string, secret: string}>
     */
    protected function configuredApps(): array
    {
        $apps = [];
        $pairs = [
            [config('services.facebook.client_id'), config('services.facebook.client_secret')],
            [config('services.facebook.testing_client_id'), config('services.facebook.testing_client_secret')],
        ];

        foreach ($pairs as [$appId, $secret]) {
            if (! empty($appId) && ! empty($secret)) {
                $apps[(string) $appId] = ['app_id' => (string) $appId, 'secret' => (string) $secret];
            }
        }

        return array_values($apps);
    }

    protected function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9\-_]+$/', $value)) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Verify a signed_request. Returns the Meta app that signed it and the app-scoped Facebook user id,
     * or null when the request is malformed, signed with an unknown secret, or has an unexpected payload.
     *
     * @return array{app_id: string, user_id: string}|null
     */
    public function verify(string $signedRequest): ?array
    {
        if ($signedRequest === '' || strlen($signedRequest) > 8192) {
            return null;
        }

        $parts = explode('.', $signedRequest);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = $parts;
        $signature = $this->base64UrlDecode($encodedSignature);
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($signature === null || $payloadJson === null) {
            return null;
        }

        $signedBy = null;
        foreach ($this->configuredApps() as $app) {
            $expected = hash_hmac('sha256', $encodedPayload, $app['secret'], true);
            if (hash_equals($expected, $signature)) {
                $signedBy = $app['app_id'];
                break;
            }
        }
        if ($signedBy === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        $userId = $payload['user_id'] ?? null;
        if (is_int($userId)) {
            $userId = (string) $userId;
        }
        if (! is_string($userId) || ! preg_match('/^\d{1,40}$/', $userId)) {
            return null;
        }

        return ['app_id' => $signedBy, 'user_id' => $userId];
    }

    /**
     * Keyed hash used to recognise repeat requests without keeping the raw Facebook user id.
     */
    protected function userHash(string $appId, string $userId): string
    {
        return hash_hmac('sha256', $appId.':'.$userId, (string) config('app.key'));
    }

    /**
     * Record (or recognise) a request and delete the Facebook-linked data for that user in that app.
     * Idempotent: the same (app, user) always gets the same confirmation code.
     */
    public function handle(string $appId, string $userId): FacebookDataDeletionRequest
    {
        $hash = $this->userHash($appId, $userId);

        $request = FacebookDataDeletionRequest::where('facebook_app_id', $appId)->where('facebook_user_hash', $hash)->first();

        if ($request) {
            $request->update([
                'status' => 'received',
                'request_count' => $request->request_count + 1,
                'requested_at' => now(),
            ]);
        } else {
            try {
                $request = FacebookDataDeletionRequest::create([
                    'confirmation_code' => bin2hex(random_bytes(16)),
                    'facebook_app_id' => $appId,
                    'facebook_user_hash' => $hash,
                    'status' => 'received',
                    'request_count' => 1,
                    'requested_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent identical request won the insert: reuse its record.
                $request = FacebookDataDeletionRequest::where('facebook_app_id', $appId)->where('facebook_user_hash', $hash)->firstOrFail();
            }
        }

        $counts = DB::transaction(fn () => $this->deleteLinkedData($appId, $userId));

        $previous = $request->deleted_counts ?? [];
        $request->update([
            'status' => 'completed',
            'completed_at' => now(),
            'deleted_counts' => [
                'facebook_pages' => (int) ($previous['facebook_pages'] ?? 0) + $counts['facebook_pages'],
                'integrations' => (int) ($previous['integrations'] ?? 0) + $counts['integrations'],
            ],
        ]);

        Log::info('[FACEBOOK DATA DELETION] Request processed', [
            'confirmation_code' => $request->confirmation_code,
            'facebook_app_id' => $appId,
            'deleted' => $counts,
        ]);

        return $request->refresh();
    }

    /**
     * Deletes ONLY the Facebook/Instagram connections (and their stored access tokens) that were granted by this
     * Facebook user through this Meta app. Workspaces, leads, campaigns, published posts, team users and
     * connections made by other people or without a recorded Facebook user are never touched.
     *
     * @return array{facebook_pages: int, integrations: int}
     */
    protected function deleteLinkedData(string $appId, string $userId): array
    {
        $pages = FacebookPage::withTrashed()
            ->where('facebook_app_id', $appId)
            ->where('facebook_user_id', $userId)
            ->get(['id', 'workspace_id', 'page_id']);

        $integrations = 0;

        // The Facebook Integration row that mirrors a deleted Page connection, unless it belongs to another user.
        foreach ($pages as $page) {
            $integrations += Integration::withTrashed()
                ->where('workspace_id', $page->workspace_id)
                ->where('platform', 'facebook')
                ->where('account_id', $page->page_id)
                ->where(fn ($q) => $q->whereNull('facebook_user_id')->orWhere(fn ($own) => $own->where('facebook_app_id', $appId)->where('facebook_user_id', $userId)))
                ->forceDelete();
        }

        // Every Facebook / Instagram integration explicitly linked to this user (includes linked Instagram accounts).
        $integrations += Integration::withTrashed()
            ->where('facebook_app_id', $appId)
            ->where('facebook_user_id', $userId)
            ->forceDelete();

        $pageCount = FacebookPage::withTrashed()->whereIn('id', $pages->pluck('id'))->forceDelete();

        return ['facebook_pages' => (int) $pageCount, 'integrations' => (int) $integrations];
    }
}
