/**
 * CENTRAL API CONFIGURATION
 * -------------------------
 * This is the only file a developer needs to edit when API URLs, keys,
 * timeouts, or platform versions change.
 *
 * IMPORTANT: In production, never expose private secrets in browser code.
 * Keep client secrets and refresh tokens on your secure backend and call that
 * backend from this dashboard.
 */
window.API_CONFIG = {
  mockMode: true,
  timeoutMs: 20000,
  apiBaseUrl: "https://your-domain.example.com/api/v1",
  tenantHeader: "X-Client-Workspace-ID",
  auth: {
    tokenStorageKey: "dm_platform_access_token",
    loginEndpoint: "/auth/login",
    otpVerifyEndpoint: "/auth/verify-otp",
    refreshEndpoint: "/auth/refresh"
  },
  modules: {
    clients: "/clients",
    dashboard: "/dashboard",
    posts: "/publishing/posts",
    leads: "/crm/leads",
    reports: "/reports",
    notifications: "/notifications",
    integrations: "/integrations"
  },
  platforms: {
    facebook: { version: "v23.0", connectPath: "/integrations/facebook/connect" },
    instagram: { version: "v23.0", connectPath: "/integrations/instagram/connect" },
    youtube: { version: "v3", connectPath: "/integrations/youtube/connect" },
    googleAnalytics: { version: "v1beta", connectPath: "/integrations/google-analytics/connect" },
    searchConsole: { version: "v1", connectPath: "/integrations/search-console/connect" },
    googleBusiness: { version: "v1", connectPath: "/integrations/google-business/connect" },
    linkedin: { version: "v2", connectPath: "/integrations/linkedin/connect", phase: 2 },
    twitter: { version: "v2", connectPath: "/integrations/twitter/connect", phase: 2 }
  },
  sync: {
    defaultRangeDays: 30,
    scheduledSyncMinutes: 60
  }
};
