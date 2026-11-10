import axiosInstance from './axiosInstance';

const isRealWorkspaceId = (id) => /^\d+$/.test(String(id ?? '')) && Number(id) > 0;

/**
 * Starts the YouTube OAuth flow for ONE client workspace.
 *
 * The popup is opened immediately (so the browser does not block it) and then pointed at the Google URL that the
 * authenticated API returns. The API validates that the workspace exists and that the signed-in user may use it, and
 * binds it into a signed, expiring state, so a stale or foreign workspace id can never reach the OAuth callback.
 *
 * Never throws. Resolves to { status: 'opened' | 'already_connected' | 'blocked' | 'error', message?, warning? }.
 * `warning` is set when Google will return to a different server than the one that started the flow.
 */
export async function openYouTubeOAuth({
  workspaceId,
  force = false,
  popupName = 'GoogleYouTubeOAuth',
  width = 600,
  height = 700,
} = {}) {
  if (!isRealWorkspaceId(workspaceId)) {
    return {
      status: 'error',
      message: 'Please select an individual client workspace before connecting YouTube.',
    };
  }

  const left = Math.max(0, Math.round(window.screen.width / 2 - width / 2));
  const top = Math.max(0, Math.round(window.screen.height / 2 - height / 2));
  const popup = window.open(
    '',
    popupName,
    `width=${width},height=${height},top=${top},left=${left},scrollbars=yes`
  );
  if (!popup || popup.closed || typeof popup.closed === 'undefined') {
    return {
      status: 'blocked',
      message: 'Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.',
    };
  }

  try {
    popup.document.title = 'Connecting to Google…';
    popup.document.body.innerText = 'Connecting to Google…';
  } catch {
    // cross-origin placeholder: harmless
  }

  try {
    const res = await axiosInstance.get('/youtube/connect', {
      params: { workspace_id: workspaceId, force: force ? 1 : 0 },
      headers: { Accept: 'application/json' },
    });

    if (res.data?.already_connected) {
      popup.close();
      return { status: 'already_connected', message: res.data.message };
    }
    if (res.data?.success && res.data.url) {
      popup.location.href = res.data.url;
      if (res.data.warning) console.warn('[YouTube OAuth]', res.data.warning);
      return { status: 'opened', warning: res.data.warning || '' };
    }

    popup.close();
    return { status: 'error', message: res.data?.message || 'Could not start the YouTube connection.' };
  } catch (err) {
    popup.close();
    return {
      status: 'error',
      message: err.response?.data?.message || 'Could not start the YouTube connection. Please try again.',
    };
  }
}
