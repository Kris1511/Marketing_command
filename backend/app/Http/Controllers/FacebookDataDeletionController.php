<?php

namespace App\Http\Controllers;

use App\Models\FacebookDataDeletionRequest;
use App\Services\FacebookDataDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public endpoints for Meta's Data Deletion Request Callback. Neither route uses authentication:
 * Meta calls the callback server-to-server, authenticated only by the signed_request signature.
 */
class FacebookDataDeletionController extends Controller
{
    public function __construct(protected FacebookDataDeletionService $deletion) {}

    /**
     * POST /api/v1/facebook/data-deletion
     * Responds with the JSON Meta requires: {"url": <status page>, "confirmation_code": <code>}.
     */
    public function callback(Request $request): JsonResponse
    {
        $signedRequest = $request->input('signed_request');

        if (! is_string($signedRequest) || $signedRequest === '') {
            return response()->json(['error' => 'missing_signed_request'], 400);
        }

        $verified = $this->deletion->verify($signedRequest);
        if ($verified === null) {
            // Never log the request itself: it contains the user id.
            Log::warning('[FACEBOOK DATA DELETION] Rejected request with an invalid signed_request.', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signed_request'], 400);
        }

        $deletionRequest = $this->deletion->handle($verified['app_id'], $verified['user_id']);

        return response()->json([
            'url' => $this->statusUrl($deletionRequest->confirmation_code),
            'confirmation_code' => $deletionRequest->confirmation_code,
        ]);
    }

    /**
     * GET /api/v1/facebook/data-deletion/status/{code}
     * Lets a person check the outcome of their request. The code is random and unguessable; no Facebook user id
     * or other personal data is returned.
     */
    public function status(string $code): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9]{16,40}$/', $code)) {
            return response()->json(['success' => false, 'message' => 'Unknown confirmation code.'], 404);
        }

        $deletionRequest = FacebookDataDeletionRequest::where('confirmation_code', $code)->first();
        if (! $deletionRequest) {
            return response()->json(['success' => false, 'message' => 'Unknown confirmation code.'], 404);
        }

        $counts = $deletionRequest->deleted_counts ?? [];
        $removed = (int) ($counts['facebook_pages'] ?? 0) + (int) ($counts['integrations'] ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'confirmation_code' => $deletionRequest->confirmation_code,
                'status' => $deletionRequest->status,
                'requested_at' => $deletionRequest->requested_at?->toIso8601String(),
                'completed_at' => $deletionRequest->completed_at?->toIso8601String(),
                'deleted' => [
                    'facebook_pages' => (int) ($counts['facebook_pages'] ?? 0),
                    'integrations' => (int) ($counts['integrations'] ?? 0),
                ],
                'message' => match (true) {
                    $deletionRequest->status !== 'completed' => 'Your request has been received and is being processed.',
                    $removed > 0 => 'Your Facebook connection data (access tokens and connected Page/Instagram records) has been deleted.',
                    default => 'Your request was processed. No Facebook connection data linked to your Facebook account was found. If you connected Facebook before this feature existed, email privacy@redmindtechnologies.com to have older records removed.',
                },
            ],
        ]);
    }

    protected function statusUrl(string $code): string
    {
        $base = config('services.facebook.frontend_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/data-deletion?code='.$code;
    }
}
