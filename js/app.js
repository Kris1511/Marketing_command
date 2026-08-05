(function () {
  const data = window.SAMPLE_DATA;
  const api = window.ApiService;

  const state = {
    currentView: "overview",
    currentClientId: data.clients[0].id,
    clients: [...data.clients],
    leads: [...data.leads],
    posts: [...data.posts],
    notifications: [...data.notifications],
    integrations: [...data.integrations]
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const currentClient = () => state.clients.find(c => c.id === state.currentClientId) || state.clients[0];

  const viewMeta = {
    overview: ["Overview", "All important updates in one place"],
    clients: ["Clients", "Manage isolated client workspaces"],
    publishing: ["Publishing", "Create, schedule, and monitor content"],
    crm: ["CRM & Leads", "Track lead ownership and conversions"],
    reports: ["Reports", "Build clear performance reports"],
    notifications: ["Notifications", "See alerts and reminders"],
    integrations: ["API Connections", "Connect and monitor platforms"],
    team: ["Team & Access", "Manage internal users and permissions"]
  };

  function escapeHtml(value = "") {
    return String(value).replace(/[&<>'"]/g, char => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;"
    })[char]);
  }

  function formatNumber(value) {
    if (value >= 1000000) return `${(value / 1000000).toFixed(1)}M`;
    if (value >= 1000) return `${(value / 1000).toFixed(1)}K`;
    return String(value);
  }

  function initials(name) {
    return name.split(/\s+/).slice(0, 2).map(word => word[0]).join("").toUpperCase();
  }

  function toast(title, message, type = "success") {
    const item = document.createElement("div");
    item.className = `toast ${type}`;
    item.innerHTML = `<div>${type === "success" ? "✓" : "!"}</div><div><strong>${escapeHtml(title)}</strong><p>${escapeHtml(message)}</p></div>`;
    $("#toastWrap").appendChild(item);
    setTimeout(() => item.remove(), 3600);
  }

  function openModal(id) { $(`#${id}`)?.classList.add("show"); }
  function closeModal(id) { $(`#${id}`)?.classList.remove("show"); }

  function navigate(view) {
    if (!viewMeta[view]) return;
    state.currentView = view;
    $$(".view").forEach(el => el.classList.toggle("active", el.id === `view-${view}`));
    $$(".nav-btn").forEach(btn => btn.classList.toggle("active", btn.dataset.view === view));
    $("#pageTitle").textContent = viewMeta[view][0];
    $("#pageSubtitle").textContent = viewMeta[view][1];
    $("#sidebar").classList.remove("open");
    window.scrollTo({ top: 0, behavior: "smooth" });
    if (view === "reports") renderReports();
    if (view === "publishing") renderCalendar();
  }

  function populateClientSelector() {
    const selector = $("#clientSelector");
    selector.innerHTML = state.clients.map(client => `<option value="${client.id}">${escapeHtml(client.name)}</option>`).join("");
    selector.value = state.currentClientId;
  }

  function metricCard(label, value, change, helper, icon) {
    const trendClass = Number(change) >= 0 ? "trend-up" : "trend-down";
    const sign = Number(change) >= 0 ? "+" : "";
    return `<div class="metric-card">
      <div class="metric-top"><span class="metric-label">${label}</span><span class="metric-icon">${icon}</span></div>
      <div class="metric-value">${value}</div>
      <div class="metric-foot"><span class="${trendClass}">${sign}${change}%</span><span>${helper}</span></div>
    </div>`;
  }

  function renderOverview() {
    const client = currentClient();
    const m = client.metrics;
    const t = client.trends;
    $("#selectedClientName").textContent = client.name;
    $("#overviewMetrics").innerHTML = [
      metricCard("Total reach", formatNumber(m.reach), t.reach, "vs previous period", "◎"),
      metricCard("Engagement", formatNumber(m.engagement), t.engagement, "vs previous period", "♡"),
      metricCard("Website traffic", formatNumber(m.traffic), t.traffic, "vs previous period", "↗"),
      metricCard("Leads generated", formatNumber(m.leads), t.leads, "vs previous period", "+")
    ].join("");

    renderChannels(client);
    renderFunnel();
    renderActivities();
    requestAnimationFrame(drawPerformanceChart);
  }

  function renderChannels(client) {
    const channelMap = {
      instagram: { name: "Instagram", initials: "IG", value: formatNumber(Math.round(client.metrics.reach * .42)), label: "Reach", change: "+18.2%" },
      facebook: { name: "Facebook", initials: "f", value: formatNumber(Math.round(client.metrics.reach * .27)), label: "Reach", change: "+9.7%" },
      youtube: { name: "YouTube", initials: "YT", value: formatNumber(Math.round(client.metrics.reach * .18)), label: "Views", change: "+13.4%" },
      googleAnalytics: { name: "Google Analytics", initials: "GA", value: formatNumber(client.metrics.traffic), label: "Users", change: `+${Math.max(1, client.trends.traffic)}%` },
      searchConsole: { name: "Search Console", initials: "SC", value: formatNumber(Math.round(client.metrics.traffic * .68)), label: "Clicks", change: "+7.8%" },
      googleBusiness: { name: "Google Business", initials: "GB", value: formatNumber(Math.round(client.metrics.reach * .09)), label: "Views", change: "+5.9%" }
    };
    const connected = client.integrations.map(key => ({ key, ...channelMap[key] })).filter(x => x.name).slice(0, 4);
    $("#channelList").innerHTML = connected.map(channel => `<div class="channel-row">
      <div class="channel-logo">${channel.initials}</div>
      <div><div class="channel-name">${channel.name}</div><div class="channel-meta"><span class="dot" style="color:var(--success)"></span> Connected</div></div>
      <div class="channel-value"><strong>${channel.value}</strong><small>${channel.change}</small></div>
    </div>`).join("") || `<div class="empty-state"><strong>No channels connected</strong>Open API Connections to connect this client.</div>`;
  }

  function renderFunnel() {
    const client = currentClient();
    const total = client.metrics.leads;
    const stages = [
      ["New Leads", total, 100],
      ["Contacted", Math.round(total * .76), 76],
      ["Qualified", Math.round(total * .42), 42],
      ["Proposal Sent", Math.round(total * .21), 21],
      ["Won", Math.round(total * (client.metrics.conversion / 100)), client.metrics.conversion]
    ];
    $("#leadFunnel").innerHTML = stages.map(([stage, value, percent]) => `<div class="funnel-row"><span>${stage}</span><div class="progress"><span style="width:${Math.min(100, percent)}%"></span></div><strong>${value}</strong></div>`).join("");
  }

  function renderActivities() {
    $("#activityList").innerHTML = data.activities.slice(0, 4).map(item => `<div class="activity-item">
      <div class="activity-icon">${item.icon}</div><div class="activity-text"><strong>${item.title}</strong><span>${item.detail}</span></div><div class="activity-time">${item.time}</div>
    </div>`).join("");
  }

  function drawPerformanceChart() {
    const canvas = $("#performanceChart");
    if (!canvas || !canvas.offsetWidth) return;
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    const ctx = canvas.getContext("2d");
    ctx.scale(dpr, dpr);
    const width = rect.width;
    const height = rect.height;
    const pad = { left: 38, right: 12, top: 18, bottom: 34 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;
    const series = data.performance;
    const max = Math.max(...series.reach) * 1.15;

    ctx.clearRect(0, 0, width, height);
    ctx.font = "11px system-ui";
    ctx.fillStyle = "#748198";
    ctx.strokeStyle = "#e7ecf3";
    ctx.lineWidth = 1;

    for (let i = 0; i <= 4; i++) {
      const y = pad.top + (plotH / 4) * i;
      ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(width - pad.right, y); ctx.stroke();
      const label = Math.round(max - (max / 4) * i);
      ctx.fillText(`${label}K`, 2, y + 4);
    }

    series.labels.forEach((label, index) => {
      const x = pad.left + (plotW / (series.labels.length - 1)) * index;
      ctx.fillText(label, x - 14, height - 10);
    });

    function line(values, color, fill) {
      const points = values.map((value, index) => ({
        x: pad.left + (plotW / (values.length - 1)) * index,
        y: pad.top + plotH - (value / max) * plotH
      }));
      if (fill) {
        const gradient = ctx.createLinearGradient(0, pad.top, 0, height - pad.bottom);
        gradient.addColorStop(0, "rgba(36,87,230,.18)");
        gradient.addColorStop(1, "rgba(36,87,230,0)");
        ctx.beginPath();
        ctx.moveTo(points[0].x, height - pad.bottom);
        points.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.lineTo(points[points.length - 1].x, height - pad.bottom);
        ctx.closePath(); ctx.fillStyle = gradient; ctx.fill();
      }
      ctx.beginPath();
      points.forEach((point, index) => index ? ctx.lineTo(point.x, point.y) : ctx.moveTo(point.x, point.y));
      ctx.strokeStyle = color; ctx.lineWidth = 2.5; ctx.lineJoin = "round"; ctx.stroke();
      points.forEach(point => { ctx.beginPath(); ctx.arc(point.x, point.y, 3.2, 0, Math.PI * 2); ctx.fillStyle = "#fff"; ctx.fill(); ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.stroke(); });
    }

    line(series.reach, "#2457e6", true);
    line(series.engagement, "#7b92c9", false);
  }

  function renderClientCards(filter = "") {
    const query = filter.trim().toLowerCase();
    const clients = state.clients.filter(c => `${c.name} ${c.industry} ${c.owner}`.toLowerCase().includes(query));
    $("#clientCards").innerHTML = clients.map(client => `<article class="client-card">
      <div class="client-card-head"><div class="initial">${escapeHtml(client.color || initials(client.name))}</div><span class="pill ${client.status === "Active" ? "success" : "warning"}">${client.status}</span></div>
      <h3>${escapeHtml(client.name)}</h3><p>${escapeHtml(client.industry)} • Contact: ${escapeHtml(client.owner)}</p>
      <div class="client-card-metrics">
        <div class="client-mini"><strong>${formatNumber(client.metrics.reach)}</strong><span>Reach</span></div>
        <div class="client-mini"><strong>${client.metrics.leads}</strong><span>Leads</span></div>
        <div class="client-mini"><strong>${client.integrations.length}</strong><span>Channels</span></div>
      </div>
      <button class="btn btn-secondary btn-block mt-12 open-client" data-client-id="${client.id}">Open workspace</button>
    </article>`).join("") || `<div class="empty-state"><strong>No client found</strong>Try a different search term.</div>`;
    $("#clientCount").textContent = state.clients.length;
    $$(".open-client").forEach(btn => btn.addEventListener("click", () => {
      state.currentClientId = btn.dataset.clientId;
      $("#clientSelector").value = state.currentClientId;
      refreshClientDependentViews();
      navigate("overview");
      toast("Workspace opened", `${currentClient().name} is now selected.`);
    }));
  }

  function previewPost() {
    $("#previewCaption").textContent = $("#postCaption").value || "Your caption will appear here.";
    $("#previewTags").textContent = $("#postHashtags").value;
    $("#previewCTA").textContent = $("#postCTA").value;
  }

  function renderCalendar() {
    const days = [
      { name: "Tue", date: "2026-08-04", no: 4 }, { name: "Wed", date: "2026-08-05", no: 5 },
      { name: "Thu", date: "2026-08-06", no: 6 }, { name: "Fri", date: "2026-08-07", no: 7 },
      { name: "Sat", date: "2026-08-08", no: 8 }, { name: "Sun", date: "2026-08-09", no: 9 },
      { name: "Mon", date: "2026-08-10", no: 10 }
    ];
    const relevantPosts = state.posts.filter(p => p.clientId === state.currentClientId);
    $("#contentCalendar").innerHTML = days.map(day => {
      const events = relevantPosts.filter(p => p.date === day.date).map(post => `<div class="calendar-event ${post.platform.toLowerCase()}"><strong>${post.time}</strong><br>${escapeHtml(post.title)}<br><span class="muted">${post.platform}</span></div>`).join("");
      return `<div class="calendar-day"><strong>${day.name} ${day.no}</strong>${events || '<div class="form-help" style="margin-top:12px">No content</div>'}</div>`;
    }).join("");
  }

  function selectedPlatforms() {
    return $$("#platformChecks input:checked").map(input => input.value);
  }

  async function submitPost(forceDraft = false) {
    const title = $("#postTitle").value.trim();
    if (!title) return toast("Title required", "Enter an internal content title.", "error");
    const platforms = selectedPlatforms();
    if (!platforms.length) return toast("Choose a platform", "Select at least one connected platform.", "error");
    const type = forceDraft ? "draft" : $("#publishType").value;
    const dateTime = $("#scheduleAt").value ? new Date($("#scheduleAt").value) : new Date();
    const payload = {
      title,
      caption: $("#postCaption").value,
      hashtags: $("#postHashtags").value,
      cta: $("#postCTA").value,
      platforms,
      status: type === "draft" ? "Draft" : type === "now" ? "Published" : "Scheduled",
      date: dateTime.toISOString().slice(0, 10),
      time: dateTime.toTimeString().slice(0, 5),
      clientId: state.currentClientId,
      platform: platforms[0]
    };
    const button = $("#submitPost");
    const old = button.textContent;
    button.disabled = true; button.textContent = "Saving...";
    try {
      const saved = type === "now" ? await api.publishing.publishNow(state.currentClientId, payload) : await api.publishing.create(state.currentClientId, payload);
      state.posts.push(saved.id ? saved : { ...payload, id: `p-${Date.now()}` });
      renderCalendar();
      toast(type === "draft" ? "Draft saved" : type === "now" ? "Content published" : "Content scheduled", `${title} was saved for ${platforms.join(", ")}.`);
    } catch (error) {
      toast("Could not save content", error.message, "error");
    } finally {
      button.disabled = false; button.textContent = old;
    }
  }

  function leadsForClient() { return state.leads.filter(lead => lead.clientId === state.currentClientId); }

  function renderLeadMetrics() {
    const leads = leadsForClient();
    const won = leads.filter(l => l.stage === "Won").length;
    const lost = leads.filter(l => l.stage === "Lost").length;
    const followups = leads.filter(l => ["Contacted", "Follow-Up", "Qualified", "Proposal Sent"].includes(l.stage)).length;
    const conversion = leads.length ? ((won / leads.length) * 100).toFixed(1) : "0.0";
    $("#leadMetrics").innerHTML = [
      `<div class="metric-card"><div class="metric-label">Total leads</div><div class="metric-value">${leads.length}</div><div class="metric-foot">Selected client</div></div>`,
      `<div class="metric-card"><div class="metric-label">Follow-ups open</div><div class="metric-value">${followups}</div><div class="metric-foot"><span class="trend-down">Action required</span></div></div>`,
      `<div class="metric-card"><div class="metric-label">Won leads</div><div class="metric-value">${won}</div><div class="metric-foot"><span class="trend-up">Converted</span></div></div>`,
      `<div class="metric-card"><div class="metric-label">Conversion rate</div><div class="metric-value">${conversion}%</div><div class="metric-foot">Won / total leads</div></div>`
    ].join("");
  }

  function stageClass(stage) {
    if (stage === "Won") return "success";
    if (stage === "Lost") return "danger";
    if (stage === "New Lead") return "info";
    if (stage === "Proposal Sent") return "warning";
    return "";
  }

  function renderLeadTable() {
    const query = $("#leadSearch")?.value.trim().toLowerCase() || "";
    const stage = $("#leadStageFilter")?.value || "";
    const leads = leadsForClient().filter(lead => {
      const matchesQuery = `${lead.name} ${lead.mobile} ${lead.email} ${lead.campaign}`.toLowerCase().includes(query);
      return matchesQuery && (!stage || lead.stage === stage);
    });
    $("#leadTableBody").innerHTML = leads.map(lead => `<tr>
      <td><div class="name-cell"><div class="initial">${initials(lead.name)}</div><div><strong>${escapeHtml(lead.name)}</strong><div class="muted">${escapeHtml(lead.mobile)}</div></div></div></td>
      <td><strong>${escapeHtml(lead.source)}</strong><div class="muted">${escapeHtml(lead.campaign)}</div></td>
      <td>${escapeHtml(lead.owner)}</td><td>${lead.created}</td>
      <td><span class="pill ${stageClass(lead.stage)}">${lead.stage}</span></td>
      <td><button class="link-button lead-action" data-lead-id="${lead.id}">Update</button></td>
    </tr>`).join("") || `<tr><td colspan="6"><div class="empty-state"><strong>No leads found</strong>Change the search or stage filter.</div></td></tr>`;
    $$(".lead-action").forEach(btn => btn.addEventListener("click", () => quickAdvanceLead(btn.dataset.leadId)));
  }

  async function quickAdvanceLead(id) {
    const lead = state.leads.find(l => l.id === id);
    if (!lead) return;
    const stages = ["New Lead", "Contacted", "Follow-Up", "Qualified", "Proposal Sent", "Won"];
    const currentIndex = stages.indexOf(lead.stage);
    const next = currentIndex >= 0 && currentIndex < stages.length - 1 ? stages[currentIndex + 1] : "Won";
    try {
      await api.crm.updateStage(state.currentClientId, id, next);
      lead.stage = next;
      renderLeadViews();
      toast("Lead updated", `${lead.name} moved to ${next}.`);
    } catch (error) { toast("Update failed", error.message, "error"); }
  }

  function renderKanban() {
    const groups = ["New Lead", "Contacted", "Qualified", "Proposal Sent", "Won"];
    const leads = leadsForClient();
    $("#leadKanban").innerHTML = groups.map(stage => {
      const items = leads.filter(l => l.stage === stage || (stage === "Contacted" && l.stage === "Follow-Up"));
      return `<div class="kanban-col"><div class="kanban-head"><span>${stage}</span><span class="pill">${items.length}</span></div>${items.map(lead => `<div class="lead-card"><strong>${escapeHtml(lead.name)}</strong><small>${escapeHtml(lead.campaign)}</small><div class="lead-card-foot"><span class="source-chip">${escapeHtml(lead.source)}</span><span class="avatar" style="width:27px;height:27px;font-size:10px">${initials(lead.owner)}</span></div></div>`).join("") || '<div class="form-help">No leads</div>'}</div>`;
    }).join("");
  }

  function renderLeadViews() {
    renderLeadMetrics();
    renderLeadTable();
    renderKanban();
  }

  function renderReports() {
    const client = currentClient();
    $("#reportClientTitle").textContent = `${client.name} – Performance Summary`;
    $("#reportReach").textContent = formatNumber(client.metrics.reach);
    $("#reportLeads").textContent = client.metrics.leads;
    $("#reportConversion").textContent = `${client.metrics.conversion}%`;
    $("#reportMetrics").innerHTML = [
      metricCard("Impressions", formatNumber(Math.round(client.metrics.reach * 1.72)), 16.4, "vs previous period", "◫"),
      metricCard("Engagement", formatNumber(client.metrics.engagement), client.trends.engagement, "vs previous period", "♡"),
      metricCard("Website users", formatNumber(client.metrics.traffic), client.trends.traffic, "vs previous period", "↗"),
      metricCard("Followers", formatNumber(client.metrics.followers), 6.8, "net audience growth", "+")
    ].join("");
    const contribution = [
      ["Instagram", 42], ["Facebook", 27], ["YouTube", 18], ["Google", 13]
    ];
    $("#reportChannels").innerHTML = contribution.map(([name, percent]) => `<div class="funnel-row"><span>${name}</span><div class="progress"><span style="width:${percent}%"></span></div><strong>${percent}%</strong></div>`).join("");
    const sources = [["Facebook Ads", 38], ["Website", 26], ["Google Forms", 18], ["Landing Pages", 12], ["Manual", 6]];
    $("#reportSources").innerHTML = sources.map(([name, percent]) => `<div class="funnel-row"><span>${name}</span><div class="progress"><span style="width:${percent}%"></span></div><strong>${percent}%</strong></div>`).join("");
  }

  function renderNotifications() {
    $("#notificationList").innerHTML = state.notifications.map(item => `<div class="notice ${item.unread ? "new" : ""}">
      <div class="notice-icon">${item.icon}</div><div><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.message)}</p></div><time>${escapeHtml(item.time)}</time>
    </div>`).join("");
    const unread = state.notifications.some(n => n.unread);
    $$(".notification-dot").forEach(dot => dot.style.display = unread ? "block" : "none");
  }

  function renderIntegrations() {
    const client = currentClient();
    $("#integrationGrid").innerHTML = state.integrations.map(item => {
      const enabled = client.integrations.includes(item.key);
      const status = item.phase === 2 ? "Phase 2" : enabled ? item.status : "Not connected";
      const statusClass = status === "Connected" ? "success" : status === "Attention" ? "warning" : status === "Phase 2" ? "info" : "";
      const disabled = item.phase === 2 ? "disabled" : "";
      return `<article class="integration-card">
        <div class="integration-head"><div class="channel-logo">${item.initials}</div><div><h3>${item.name}</h3><p>Phase ${item.phase} integration</p></div></div>
        <div class="integration-status"><span class="pill ${statusClass}"><span class="dot"></span>${status}</span><small class="muted">${enabled ? item.lastSync : "No data"}</small></div>
        <div class="integration-actions">
          <button type="button" class="btn btn-secondary btn-sm integration-connect" data-key="${item.key}" ${disabled}>${enabled ? "Reconnect" : "Connect"}</button>
          <button type="button" class="btn btn-primary btn-sm integration-test" data-key="${item.key}" ${!enabled || disabled ? "disabled" : ""}>Test</button>
        </div>
      </article>`;
    }).join("");
    $$(".integration-test").forEach(btn => btn.addEventListener("click", () => testIntegration(btn)));
    $$(".integration-connect").forEach(btn => btn.addEventListener("click", () => connectIntegration(btn.dataset.key)));
  }

  async function testIntegration(button) {
    const key = button.dataset.key;
    const old = button.textContent; button.disabled = true; button.textContent = "Testing...";
    try {
      const result = await api.integrations.test(state.currentClientId, key);
      toast("Connection working", `API responded successfully in ${result.latencyMs || 184} ms.`);
    } catch (error) { toast("Connection failed", error.message, "error"); }
    finally { button.disabled = false; button.textContent = old; }
  }

  function connectIntegration(key) {
    if (!currentClient().integrations.includes(key)) currentClient().integrations.push(key);
    renderIntegrations(); renderChannels(currentClient());
    toast("Demo connection enabled", `${state.integrations.find(i => i.key === key)?.name || key} is now shown as connected. In production, this button opens the backend OAuth URL.`);
  }

  async function syncAll(triggerButton) {
    const button = triggerButton || $("#quickSync");
    const old = button.textContent; button.disabled = true; button.textContent = "↻";
    try {
      await api.dashboard.syncAll(state.currentClientId);
      toast("Sync completed", `${currentClient().name} data is up to date.`);
    } catch (error) { toast("Sync failed", error.message, "error"); }
    finally { button.disabled = false; button.textContent = old; }
  }

  function downloadCsv() {
    const client = currentClient();
    const leads = leadsForClient();
    const rows = [
      ["Digital Marketing Performance Report"],
      ["Client", client.name],
      ["Generated", new Date().toLocaleString()],
      [],
      ["KPI", "Value"],
      ["Reach", client.metrics.reach],
      ["Engagement", client.metrics.engagement],
      ["Website Traffic", client.metrics.traffic],
      ["Leads", client.metrics.leads],
      ["Conversion Rate", `${client.metrics.conversion}%`],
      [],
      ["Lead Name", "Source", "Campaign", "Owner", "Stage", "Created"],
      ...leads.map(l => [l.name, l.source, l.campaign, l.owner, l.stage, l.created])
    ];
    const csv = rows.map(row => row.map(value => `"${String(value ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a"); a.href = url; a.download = `${client.name.replace(/\s+/g, "-").toLowerCase()}-marketing-report.csv`; a.click();
    URL.revokeObjectURL(url);
    toast("Report downloaded", "The CSV file can be opened in Excel.");
  }

  async function createClient() {
    const name = $("#newClientName").value.trim();
    if (!name) return toast("Client name required", "Enter the business or client name.", "error");
    const selectedIntegrations = $$("#clientModal .check-card input:checked").map(i => i.value);
    const payload = {
      name,
      industry: $("#newClientIndustry").value.trim() || "Not specified",
      owner: $("#newClientOwner").value.trim() || "Not assigned",
      color: initials(name),
      integrations: selectedIntegrations,
      metrics: { reach: 0, engagement: 0, followers: 0, traffic: 0, leads: 0, conversion: 0, scheduled: 0 },
      trends: { reach: 0, engagement: 0, traffic: 0, leads: 0 }
    };
    try {
      const created = await api.clients.create(payload);
      state.clients.push({ ...payload, ...created });
      populateClientSelector(); renderClientCards(); closeModal("clientModal");
      toast("Client workspace created", `${name} is ready for channel connections.`);
      ["newClientName", "newClientIndustry", "newClientOwner"].forEach(id => $(`#${id}`).value = "");
      $$("#clientModal input[type=checkbox]").forEach(i => i.checked = false);
    } catch (error) { toast("Could not create client", error.message, "error"); }
  }

  async function createLead() {
    const name = $("#leadName").value.trim();
    const mobile = $("#leadMobile").value.trim();
    if (!name || !mobile) return toast("Lead details required", "Enter the lead name and mobile number.", "error");
    const payload = {
      name, mobile,
      email: $("#leadEmail").value.trim(),
      source: $("#leadSource").value,
      campaign: $("#leadCampaign").value.trim() || "General enquiry",
      clientId: state.currentClientId,
      created: new Date().toISOString().slice(0, 10),
      stage: "New Lead",
      owner: $("#leadOwner").value,
      notes: $("#leadNotes").value.trim()
    };
    try {
      const created = await api.crm.create(state.currentClientId, payload);
      state.leads.unshift({ ...payload, ...created });
      closeModal("leadModal"); renderLeadViews();
      toast("Lead added", `${name} was assigned to ${payload.owner}.`);
      ["leadName", "leadMobile", "leadEmail", "leadCampaign", "leadNotes"].forEach(id => $(`#${id}`).value = "");
    } catch (error) { toast("Could not add lead", error.message, "error"); }
  }

  function refreshClientDependentViews() {
    renderOverview();
    renderCalendar();
    renderLeadViews();
    renderReports();
    renderIntegrations();
    updatePublishingPlatforms();
  }

  function updatePublishingPlatforms() {
    const client = currentClient();
    const map = { Facebook: "facebook", Instagram: "instagram", YouTube: "youtube", LinkedIn: "linkedin", X: "twitter" };
    $$("#platformChecks input").forEach(input => {
      const key = map[input.value];
      const phase2 = ["linkedin", "twitter"].includes(key);
      input.disabled = phase2 || !client.integrations.includes(key);
      if (input.disabled) input.checked = false;
    });
  }

  function bindEvents() {
    $$(".nav-btn").forEach(btn => btn.addEventListener("click", () => navigate(btn.dataset.view)));
    $$('[data-nav-target]').forEach(btn => btn.addEventListener("click", () => navigate(btn.dataset.navTarget)));
    $("#mobileMenu").addEventListener("click", () => $("#sidebar").classList.toggle("open"));
    $("#clientSelector").addEventListener("change", event => {
      state.currentClientId = event.target.value;
      refreshClientDependentViews();
      toast("Client changed", `${currentClient().name} workspace loaded.`);
    });
    $("#quickSync").addEventListener("click", event => syncAll(event.currentTarget));
    $("#syncAllIntegrations").addEventListener("click", event => syncAll(event.currentTarget));
    $("#clientSearch").addEventListener("input", e => renderClientCards(e.target.value));
    $("#leadSearch").addEventListener("input", renderLeadTable);
    $("#leadStageFilter").addEventListener("change", renderLeadTable);
    $("#addClientBtn").addEventListener("click", () => openModal("clientModal"));
    $("#addLeadBtn").addEventListener("click", () => openModal("leadModal"));
    $("#openHelp").addEventListener("click", () => openModal("helpModal"));
    $("#addUserBtn").addEventListener("click", () => toast("Team setup", "Connect this button to your user-management API endpoint."));
    $$('[data-close-modal]').forEach(btn => btn.addEventListener("click", () => closeModal(btn.dataset.closeModal)));
    $$(".modal-backdrop").forEach(modal => modal.addEventListener("click", event => { if (event.target === modal) closeModal(modal.id); }));
    $("#createClient").addEventListener("click", createClient);
    $("#createLead").addEventListener("click", createLead);
    ["postCaption", "postHashtags", "postCTA"].forEach(id => $(`#${id}`).addEventListener("input", previewPost));
    $("#mediaFile").addEventListener("change", event => {
      const file = event.target.files[0];
      if (!file) return;
      $("#previewMedia").textContent = file.name;
      if (file.type.startsWith("image/")) {
        const reader = new FileReader();
        reader.onload = e => { $("#previewMedia").style.background = `center/cover url('${e.target.result}')`; $("#previewMedia").textContent = ""; };
        reader.readAsDataURL(file);
      }
    });
    $("#publishType").addEventListener("change", event => $("#scheduleField").classList.toggle("hidden", event.target.value !== "schedule"));
    $("#submitPost").addEventListener("click", () => submitPost(false));
    $("#publishTop").addEventListener("click", () => submitPost(false));
    $("#saveDraft").addEventListener("click", () => submitPost(true));
    $("#saveDraftTop").addEventListener("click", () => submitPost(true));
    $("#markAllRead").addEventListener("click", async () => {
      await api.notifications.markAllRead(); state.notifications.forEach(n => n.unread = false); renderNotifications(); toast("Notifications updated", "All notifications are marked as read.");
    });
    $("#saveAlerts").addEventListener("click", () => toast("Preferences saved", "Alert settings were updated."));
    $("#downloadCsv").addEventListener("click", downloadCsv);
    $("#printReport").addEventListener("click", () => window.print());
    $("#generateReport").addEventListener("click", async event => {
      const button = event.currentTarget, old = button.textContent; button.disabled = true; button.textContent = "Generating...";
      try {
        await api.reports.generate(state.currentClientId, { platform: $("#reportPlatform").value, period: $("#reportPeriod").value, compare: $("#reportCompare").value });
        renderReports(); toast("Report generated", "The latest filters are applied to the report preview.");
      } catch (error) { toast("Report failed", error.message, "error"); }
      finally { button.disabled = false; button.textContent = old; }
    });
    $("#performanceRange").addEventListener("change", () => { drawPerformanceChart(); toast("Date range changed", "The chart has been refreshed with sample data."); });
    window.addEventListener("resize", () => { if (state.currentView === "overview") drawPerformanceChart(); });
    document.addEventListener("keydown", event => { if (event.key === "Escape") $$(".modal-backdrop.show").forEach(modal => closeModal(modal.id)); });
  }

  function init() {
    populateClientSelector();
    renderClientCards();
    renderNotifications();
    previewPost();
    bindEvents();
    refreshClientDependentViews();
    $("#userAvatar").textContent = data.user.initials;
  }

  document.addEventListener("DOMContentLoaded", init);
})();
