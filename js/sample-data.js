window.SAMPLE_DATA = {
  user: { name: "Priya S", role: "Digital Marketing Manager", initials: "PS" },
  clients: [
    {
      id: "cl-001",
      name: "Aara Wellness",
      industry: "Wellness & Healthcare",
      owner: "Meena",
      status: "Active",
      color: "AW",
      integrations: ["instagram", "facebook", "googleAnalytics", "googleBusiness"],
      metrics: { reach: 128450, engagement: 7840, followers: 24860, traffic: 18420, leads: 186, conversion: 12.4, scheduled: 14 },
      trends: { reach: 18.6, engagement: 11.8, traffic: 9.2, leads: 15.3 }
    },
    {
      id: "cl-002",
      name: "Fast Logistics",
      industry: "Logistics",
      owner: "Danesh",
      status: "Active",
      color: "FL",
      integrations: ["facebook", "youtube", "googleAnalytics", "searchConsole"],
      metrics: { reach: 84200, engagement: 4120, followers: 12360, traffic: 9250, leads: 104, conversion: 9.8, scheduled: 8 },
      trends: { reach: 8.4, engagement: 6.1, traffic: 12.7, leads: 7.2 }
    },
    {
      id: "cl-003",
      name: "I2 Studio",
      industry: "Photography",
      owner: "Ravi",
      status: "Active",
      color: "I2",
      integrations: ["instagram", "youtube", "googleBusiness"],
      metrics: { reach: 196300, engagement: 14280, followers: 38120, traffic: 22100, leads: 248, conversion: 14.6, scheduled: 22 },
      trends: { reach: 22.1, engagement: 17.9, traffic: 14.2, leads: 19.8 }
    },
    {
      id: "cl-004",
      name: "MM Associates",
      industry: "Professional Services",
      owner: "Karthik",
      status: "Setup",
      color: "MM",
      integrations: ["googleAnalytics", "searchConsole"],
      metrics: { reach: 26500, engagement: 1260, followers: 4600, traffic: 6800, leads: 41, conversion: 6.2, scheduled: 3 },
      trends: { reach: 4.8, engagement: -2.1, traffic: 7.4, leads: 2.5 }
    }
  ],
  performance: {
    labels: ["Jul 6", "Jul 10", "Jul 14", "Jul 18", "Jul 22", "Jul 26", "Jul 30", "Aug 3"],
    reach: [62, 69, 66, 76, 81, 86, 93, 104],
    engagement: [31, 34, 39, 37, 45, 49, 54, 58]
  },
  channels: [
    { key: "instagram", name: "Instagram", initials: "IG", value: "82.4K", label: "Reach", change: "+18.2%", status: "Connected" },
    { key: "facebook", name: "Facebook", initials: "f", value: "46.1K", label: "Reach", change: "+9.7%", status: "Connected" },
    { key: "youtube", name: "YouTube", initials: "YT", value: "31.6K", label: "Views", change: "+13.4%", status: "Connected" },
    { key: "googleAnalytics", name: "Website", initials: "GA", value: "18.4K", label: "Users", change: "+9.2%", status: "Connected" }
  ],
  funnel: [
    { stage: "New Leads", value: 186, percent: 100 },
    { stage: "Contacted", value: 142, percent: 76 },
    { stage: "Qualified", value: 78, percent: 42 },
    { stage: "Proposal Sent", value: 39, percent: 21 },
    { stage: "Won", value: 23, percent: 12 }
  ],
  activities: [
    { icon: "✓", title: "Instagram post published", detail: "Aara Wellness • Morning yoga reel", time: "12 min" },
    { icon: "+", title: "New lead received", detail: "Facebook Lead Ads • Priyanka R", time: "26 min" },
    { icon: "↻", title: "Google Analytics synced", detail: "18,420 website users updated", time: "1 hr" },
    { icon: "!", title: "YouTube token needs attention", detail: "Fast Logistics • Reconnect before Aug 8", time: "2 hr" },
    { icon: "★", title: "Lead marked as Won", detail: "I2 Studio • Wedding package enquiry", time: "3 hr" }
  ],
  posts: [
    { id: "p-001", title: "Monsoon Wellness Tips", platform: "Instagram", date: "2026-08-05", time: "10:30", status: "Scheduled", clientId: "cl-001" },
    { id: "p-002", title: "Customer Success Story", platform: "Facebook", date: "2026-08-06", time: "17:00", status: "Scheduled", clientId: "cl-001" },
    { id: "p-003", title: "Studio Tour Reel", platform: "Instagram", date: "2026-08-07", time: "19:30", status: "Scheduled", clientId: "cl-003" },
    { id: "p-004", title: "Air Cargo Process Explainer", platform: "YouTube", date: "2026-08-08", time: "11:00", status: "Draft", clientId: "cl-002" },
    { id: "p-005", title: "SEO Service Benefits", platform: "Facebook", date: "2026-08-09", time: "09:30", status: "Scheduled", clientId: "cl-004" }
  ],
  leads: [
    { id: "L-1001", name: "Priyanka Raj", mobile: "+91 98765 22110", email: "priyanka@example.com", source: "Facebook Lead Ad", campaign: "Yoga Trial August", clientId: "cl-001", created: "2026-08-04", stage: "New Lead", owner: "Nisha", notes: "Interested in evening class" },
    { id: "L-1002", name: "Arun Kumar", mobile: "+91 98844 31020", email: "arun@example.com", source: "Website Form", campaign: "Corporate Logistics", clientId: "cl-002", created: "2026-08-04", stage: "Contacted", owner: "Vijay", notes: "Requested a call after 4 PM" },
    { id: "L-1003", name: "Swetha M", mobile: "+91 90031 55661", email: "swetha@example.com", source: "Instagram", campaign: "Wedding Photography", clientId: "cl-003", created: "2026-08-03", stage: "Qualified", owner: "Nisha", notes: "Wedding in December" },
    { id: "L-1004", name: "Mohammed Irfan", mobile: "+91 94441 12881", email: "irfan@example.com", source: "Google Lead Form", campaign: "SEO Consultation", clientId: "cl-004", created: "2026-08-03", stage: "Proposal Sent", owner: "Kavin", notes: "Proposal sent by email" },
    { id: "L-1005", name: "Rakesh P", mobile: "+91 98402 77118", email: "rakesh@example.com", source: "Landing Page", campaign: "Premium Photoshoot", clientId: "cl-003", created: "2026-08-02", stage: "Won", owner: "Vijay", notes: "Advance received" },
    { id: "L-1006", name: "Divya S", mobile: "+91 97908 55120", email: "divya@example.com", source: "Manual Entry", campaign: "Yoga Membership", clientId: "cl-001", created: "2026-08-02", stage: "Follow-Up", owner: "Nisha", notes: "Follow up on Friday" },
    { id: "L-1007", name: "Joseph Mathew", mobile: "+91 98471 33220", email: "joseph@example.com", source: "Website Form", campaign: "Freight Enquiry", clientId: "cl-002", created: "2026-08-01", stage: "Lost", owner: "Kavin", notes: "Budget mismatch" },
    { id: "L-1008", name: "Lakshmi Devi", mobile: "+91 99620 11660", email: "lakshmi@example.com", source: "Facebook Lead Ad", campaign: "Portrait Package", clientId: "cl-003", created: "2026-08-01", stage: "New Lead", owner: "Vijay", notes: "Asked for package details" }
  ],
  notifications: [
    { id: 1, type: "lead", icon: "+", title: "New lead assigned to Nisha", message: "Priyanka Raj from Facebook Lead Ads", time: "12 minutes ago", unread: true },
    { id: 2, type: "publish", icon: "✓", title: "Post published successfully", message: "Morning Yoga Reel was published to Instagram", time: "28 minutes ago", unread: true },
    { id: 3, type: "warning", icon: "!", title: "YouTube connection expiring", message: "Fast Logistics account must be reconnected before August 8", time: "2 hours ago", unread: true },
    { id: 4, type: "reminder", icon: "⏰", title: "Follow-up due today", message: "3 leads require follow-up before 5:00 PM", time: "3 hours ago", unread: false },
    { id: 5, type: "report", icon: "▥", title: "Monthly report is ready", message: "July 2026 performance report for I2 Studio", time: "Yesterday", unread: false }
  ],
  integrations: [
    { key: "facebook", name: "Facebook Pages", initials: "f", phase: 1, status: "Connected", lastSync: "8 min ago", accounts: 3 },
    { key: "instagram", name: "Instagram Business", initials: "IG", phase: 1, status: "Connected", lastSync: "8 min ago", accounts: 3 },
    { key: "youtube", name: "YouTube Channels", initials: "YT", phase: 1, status: "Attention", lastSync: "2 hr ago", accounts: 2 },
    { key: "googleAnalytics", name: "Google Analytics", initials: "GA", phase: 1, status: "Connected", lastSync: "18 min ago", accounts: 3 },
    { key: "searchConsole", name: "Search Console", initials: "SC", phase: 1, status: "Connected", lastSync: "24 min ago", accounts: 2 },
    { key: "googleBusiness", name: "Google Business Profile", initials: "GB", phase: 1, status: "Connected", lastSync: "31 min ago", accounts: 2 },
    { key: "linkedin", name: "LinkedIn Pages", initials: "in", phase: 2, status: "Phase 2", lastSync: "Not connected", accounts: 0 },
    { key: "twitter", name: "X / Twitter", initials: "X", phase: 2, status: "Phase 2", lastSync: "Not connected", accounts: 0 }
  ]
};
