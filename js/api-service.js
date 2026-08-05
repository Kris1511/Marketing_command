/**
 * CENTRAL API SERVICE
 * -------------------
 * Every network request from the dashboard passes through this file.
 * UI files never call fetch() directly. This keeps authentication, error
 * handling, tenant headers, logging, and future API changes in one place.
 */
(function () {
  const config = window.API_CONFIG;

  const delay = (ms = 500) => new Promise(resolve => setTimeout(resolve, ms));

  function getToken() {
    try {
      return sessionStorage.getItem(config.auth.tokenStorageKey) || "";
    } catch (_) {
      return "";
    }
  }

  async function request(path, options = {}) {
    const {
      method = "GET",
      clientId = "",
      body,
      headers = {},
      mockResponse = null,
      mockDelay = 450
    } = options;

    if (config.mockMode) {
      await delay(mockDelay);
      if (typeof mockResponse === "function") return mockResponse();
      return structuredClone(mockResponse);
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), config.timeoutMs);
    const token = getToken();

    try {
      const response = await fetch(`${config.apiBaseUrl}${path}`, {
        method,
        signal: controller.signal,
        headers: {
          "Content-Type": "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(clientId ? { [config.tenantHeader]: clientId } : {}),
          ...headers
        },
        body: body ? JSON.stringify(body) : undefined
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        const message = payload.message || `Request failed with status ${response.status}`;
        throw new Error(message);
      }
      return payload;
    } catch (error) {
      if (error.name === "AbortError") throw new Error("The server took too long to respond.");
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  window.ApiService = {
    request,
    auth: {
      login: credentials => request(config.auth.loginEndpoint, { method: "POST", body: credentials, mockResponse: { success: true, otpRequired: true } }),
      verifyOtp: payload => request(config.auth.otpVerifyEndpoint, { method: "POST", body: payload, mockResponse: { success: true, accessToken: "demo-token" } })
    },
    clients: {
      list: () => request(config.modules.clients, { mockResponse: window.SAMPLE_DATA.clients }),
      create: payload => request(config.modules.clients, { method: "POST", body: payload, mockResponse: () => ({ ...payload, id: `cl-${Date.now()}`, status: "Setup" }) })
    },
    dashboard: {
      summary: clientId => request(`${config.modules.dashboard}/summary`, { clientId, mockResponse: () => window.SAMPLE_DATA.clients.find(c => c.id === clientId) }),
      syncAll: clientId => request(`${config.modules.integrations}/sync`, { method: "POST", clientId, mockResponse: { success: true, syncedAt: new Date().toISOString() }, mockDelay: 900 })
    },
    publishing: {
      list: clientId => request(config.modules.posts, { clientId, mockResponse: () => window.SAMPLE_DATA.posts.filter(p => !clientId || p.clientId === clientId) }),
      create: (clientId, payload) => request(config.modules.posts, { method: "POST", clientId, body: payload, mockResponse: () => ({ ...payload, id: `p-${Date.now()}` }) }),
      publishNow: (clientId, payload) => request(`${config.modules.posts}/publish-now`, { method: "POST", clientId, body: payload, mockResponse: { success: true, platformResults: payload.platforms || [] }, mockDelay: 900 })
    },
    crm: {
      list: clientId => request(config.modules.leads, { clientId, mockResponse: () => window.SAMPLE_DATA.leads.filter(l => !clientId || l.clientId === clientId) }),
      create: (clientId, payload) => request(config.modules.leads, { method: "POST", clientId, body: payload, mockResponse: () => ({ ...payload, id: `L-${Date.now()}` }) }),
      updateStage: (clientId, leadId, stage) => request(`${config.modules.leads}/${leadId}/stage`, { method: "PATCH", clientId, body: { stage }, mockResponse: { success: true } })
    },
    reports: {
      generate: (clientId, filters) => request(`${config.modules.reports}/generate`, { method: "POST", clientId, body: filters, mockResponse: { success: true, reportId: `R-${Date.now()}` }, mockDelay: 850 })
    },
    notifications: {
      list: () => request(config.modules.notifications, { mockResponse: window.SAMPLE_DATA.notifications }),
      markAllRead: () => request(`${config.modules.notifications}/read-all`, { method: "POST", mockResponse: { success: true } })
    },
    integrations: {
      list: clientId => request(config.modules.integrations, { clientId, mockResponse: window.SAMPLE_DATA.integrations }),
      test: (clientId, platformKey) => request(`${config.modules.integrations}/${platformKey}/test`, { method: "POST", clientId, mockResponse: { success: true, latencyMs: 184 }, mockDelay: 700 }),
      connectUrl: platformKey => `${config.apiBaseUrl}${config.platforms[platformKey]?.connectPath || `${config.modules.integrations}/${platformKey}/connect`}`
    }
  };
})();
