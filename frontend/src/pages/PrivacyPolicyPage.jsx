import React from 'react';
import { Link } from 'react-router-dom';
import {
  Shield,
  Lock,
  Eye,
  Database,
  ExternalLink,
  ArrowLeft,
  CheckCircle2,
  FileText,
  Trash2,
  Mail,
  Building,
  KeyRound,
  Globe,
  Share2,
  AlertCircle
} from 'lucide-react';

export default function PrivacyPolicyPage() {
  const lastUpdated = 'October 13, 2026';

  return (
    <div
      style={{
        minHeight: '100vh',
        background: '#f8fafc',
        color: '#1e293b',
        fontFamily: 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        lineHeight: 1.6,
      }}
    >
      {/* 1. Top Navigation Bar */}
      <header
        style={{
          background: 'rgba(255, 255, 255, 0.95)',
          backdropFilter: 'blur(10px)',
          borderBottom: '1px solid #e2e8f0',
          position: 'sticky',
          top: 0,
          zIndex: 50,
          padding: '14px 24px',
        }}
      >
        <div
          style={{
            maxWidth: '1140px',
            margin: '0 auto',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            flexWrap: 'wrap',
            gap: '12px',
          }}
        >
          {/* Brand Mark */}
          <Link
            to="/"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '12px',
              textDecoration: 'none',
              color: '#0f172a',
            }}
          >
            <div
              style={{
                width: '38px',
                height: '38px',
                borderRadius: '10px',
                background: '#2457e6',
                color: '#ffffff',
                display: 'grid',
                placeItems: 'center',
                fontSize: '16px',
                fontWeight: '900',
                letterSpacing: '-0.04em',
                boxShadow: '0 2px 6px rgba(36, 87, 230, 0.3)',
              }}
            >
              RM
            </div>
            <div>
              <strong style={{ fontSize: '16px', fontWeight: '800', display: 'block', lineHeight: 1.2 }}>
                Marketing Command
              </strong>
              <span style={{ fontSize: '11.5px', color: '#64748b', display: 'block' }}>
                By Redmind Technologies
              </span>
            </div>
          </Link>

          {/* Quick Actions */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <Link
              to="/login"
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
                padding: '8px 16px',
                borderRadius: '8px',
                background: '#2457e6',
                color: '#ffffff',
                fontSize: '13px',
                fontWeight: '600',
                textDecoration: 'none',
                transition: 'background 0.15s ease',
              }}
            >
              <ArrowLeft size={14} /> Back to Sign In
            </Link>
          </div>
        </div>
      </header>

      {/* 2. Hero Header Banner */}
      <section
        style={{
          background: 'linear-gradient(135deg, #0f1f3d 0%, #172b52 100%)',
          color: '#ffffff',
          padding: '48px 24px 44px',
          borderBottom: '1px solid rgba(255, 255, 255, 0.1)',
        }}
      >
        <div style={{ maxWidth: '1140px', margin: '0 auto' }}>
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              background: 'rgba(255, 255, 255, 0.12)',
              border: '1px solid rgba(255, 255, 255, 0.2)',
              padding: '4px 12px',
              borderRadius: '20px',
              fontSize: '12px',
              fontWeight: '600',
              marginBottom: '16px',
              color: '#93c5fd',
            }}
          >
            <Shield size={14} /> Legal &amp; Data Security Disclosures
          </div>

          <h1
            style={{
              margin: '0 0 12px',
              fontSize: '32px',
              fontWeight: '800',
              letterSpacing: '-0.02em',
              lineHeight: 1.25,
            }}
          >
            Privacy Policy &amp; OAuth Data Disclosure
          </h1>

          <p
            style={{
              margin: '0 0 20px',
              fontSize: '15px',
              color: 'rgba(255, 255, 255, 0.85)',
              maxWidth: '780px',
              lineHeight: 1.55,
            }}
          >
            This Privacy Policy describes how Marketing Command collects, accesses, stores, and protects your information, including Google Analytics 4, Google Search Console, and other connected third-party integration data.
          </p>

          <div
            style={{
              display: 'flex',
              flexWrap: 'wrap',
              gap: '16px',
              fontSize: '12.5px',
              color: 'rgba(255, 255, 255, 0.7)',
            }}
          >
            <div>
              <strong>Application:</strong> Marketing Command
            </div>
            <div>•</div>
            <div>
              <strong>Organization:</strong> Redmind Technologies
            </div>
            <div>•</div>
            <div>
              <strong>Last Updated:</strong> {lastUpdated}
            </div>
            <div>•</div>
            <div>
              <strong>Status:</strong> Public Policy
            </div>
          </div>
        </div>
      </section>

      {/* 3. Main Policy Content Layout */}
      <main
        style={{
          maxWidth: '1140px',
          margin: '0 auto',
          padding: '40px 24px 60px',
          display: 'grid',
          gridTemplateColumns: 'minmax(0, 1fr)',
          gap: '32px',
        }}
      >
        {/* Important Google Limited Use Callout Banner */}
        <div
          style={{
            background: 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)',
            border: '2px solid #93c5fd',
            borderRadius: '14px',
            padding: '22px 24px',
            boxShadow: '0 4px 12px rgba(36, 87, 230, 0.08)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: '14px' }}>
            <div
              style={{
                width: '38px',
                height: '38px',
                borderRadius: '10px',
                background: '#2457e6',
                color: '#fff',
                display: 'grid',
                placeItems: 'center',
                flexShrink: 0,
                marginTop: '2px',
              }}
            >
              <Lock size={20} />
            </div>
            <div>
              <h3 style={{ margin: '0 0 6px', fontSize: '16px', color: '#1e3a8a', fontWeight: '800' }}>
                Google API Services User Data Policy Compliance (Limited Use Disclosure)
              </h3>
              <p style={{ margin: '0 0 10px', fontSize: '13.5px', color: '#1e40af', lineHeight: 1.6 }}>
                <strong>Marketing Command&apos;s use and transfer to any other app of information received from Google APIs will adhere to the{' '}
                <a
                  href="https://developers.google.com/terms/api-services-user-data-policy"
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{ color: '#1d4ed8', fontWeight: '700', textDecoration: 'underline' }}
                >
                  Google API Services User Data Policy
                </a>, including the Limited Use requirements.</strong>
              </p>
              <ul style={{ margin: 0, paddingLeft: '18px', fontSize: '13px', color: '#1e40af' }}>
                <li>We do not sell, rent, or trade Google user data to any third party.</li>
                <li>We do not use Google user data for advertising, retargeting, or data brokering.</li>
                <li>We do not use Google user data to train machine learning or artificial intelligence models.</li>
                <li>We access Google API data solely to provide reporting and performance dashboards requested by authorized workspace users.</li>
              </ul>
            </div>
          </div>
        </div>

        {/* Policy Content Sections Card */}
        <div
          style={{
            background: '#ffffff',
            borderRadius: '16px',
            border: '1px solid #e2e8f0',
            padding: '36px 36px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.04)',
          }}
        >
          {/* Section 1: Overview & Scope */}
          <section id="overview" style={{ marginBottom: '36px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#eff6ff', display: 'grid', placeItems: 'center', color: '#2457e6' }}>
                <Building size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                1. Overview and Scope
              </h2>
            </div>
            <p style={{ fontSize: '14px', color: '#475569', marginBottom: '12px' }}>
              <strong>Marketing Command</strong> is an operations and marketing performance management platform developed and operated by <strong>Redmind Technologies</strong> (&quot;we&quot;, &quot;our&quot;, or &quot;us&quot;), available at{' '}
              <a href="https://marketing-command.redmindtechnologies.com" style={{ color: '#2457e6', fontWeight: '600', textDecoration: 'none' }}>
                https://marketing-command.redmindtechnologies.com
              </a>.
            </p>
            <p style={{ fontSize: '14px', color: '#475569', margin: 0 }}>
              This policy applies to all users, administrators, and clients accessing Marketing Command workspaces. It covers the information collected directly by our services as well as information accessed through authorized third-party connections, including <strong>Google Analytics 4 (GA4)</strong>, <strong>Google Search Console (GSC)</strong>, <strong>Meta / Facebook Pages</strong>, <strong>Instagram Business</strong>, and <strong>YouTube</strong>.
            </p>
          </section>

          <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '30px 0' }} />

          {/* Section 2: Information We Collect and Access */}
          <section id="data-collected" style={{ marginBottom: '36px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#ecfdf5', display: 'grid', placeItems: 'center', color: '#059669' }}>
                <Database size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                2. Information We Collect and Access
              </h2>
            </div>

            <p style={{ fontSize: '14px', color: '#475569', marginBottom: '14px' }}>
              We collect and process only the minimum necessary data to provide marketing operations, campaign monitoring, and executive reporting.
            </p>

            <div style={{ display: 'grid', gap: '16px' }}>
              {/* Account Data */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <h4 style={{ margin: '0 0 6px', fontSize: '14px', color: '#0f172a', fontWeight: '700' }}>
                  A. Workspace Account Information
                </h4>
                <p style={{ margin: 0, fontSize: '13px', color: '#475569' }}>
                  When you create or use an account in Marketing Command, we store your name, email address, password hash, assigned workspace identifiers, role permissions, and audit log timestamps.
                </p>
              </div>

              {/* Google Analytics 4 */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <h4 style={{ margin: '0 0 6px', fontSize: '14px', color: '#0f172a', fontWeight: '700' }}>
                  B. Google Analytics 4 (GA4) Data
                </h4>
                <p style={{ margin: '0 0 8px', fontSize: '13px', color: '#475569' }}>
                  When you connect Google Analytics, we query the Google Analytics Data API v1beta and Google Analytics Admin API to retrieve aggregate website performance statistics for your authorized GA4 property:
                </p>
                <ul style={{ margin: 0, paddingLeft: '20px', fontSize: '13px', color: '#475569' }}>
                  <li>Active users and new user visit counts</li>
                  <li>Session totals, engagement rates, and average engagement duration</li>
                  <li>Traffic acquisition breakdown (session source/medium)</li>
                  <li>Daily aggregate traffic trends over selected calendar date ranges</li>
                  <li>GA4 Property identifiers and property display names</li>
                </ul>
              </div>

              {/* Google Search Console */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <h4 style={{ margin: '0 0 6px', fontSize: '14px', color: '#0f172a', fontWeight: '700' }}>
                  C. Google Search Console (GSC) Data
                </h4>
                <p style={{ margin: '0 0 8px', fontSize: '13px', color: '#475569' }}>
                  When you connect Google Search Console, we query the Google Webmasters Search Analytics API to retrieve search performance statistics for your authorized website property:
                </p>
                <ul style={{ margin: 0, paddingLeft: '20px', fontSize: '13px', color: '#475569' }}>
                  <li>Total search visitor clicks and impressions</li>
                  <li>Average click-through rate (CTR) and average mean search position</li>
                  <li>Top search queries and keywords bringing searchers to your domain</li>
                  <li>Daily aggregate organic search performance over selected date ranges</li>
                  <li>Verified site property URLs (e.g., <code>sc-domain:yourdomain.com</code>)</li>
                </ul>
              </div>

              {/* Other Platforms */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <h4 style={{ margin: '0 0 6px', fontSize: '14px', color: '#0f172a', fontWeight: '700' }}>
                  D. Other Connected Marketing Platforms
                </h4>
                <p style={{ margin: 0, fontSize: '13px', color: '#475569' }}>
                  For Meta/Facebook Pages, Instagram Business, and YouTube channels, we access page/channel post reach, engagement metrics, comment threads, and published post status to provide unified multi-channel publishing and monitoring.
                </p>
              </div>
            </div>
          </section>

          <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '30px 0' }} />

          {/* Section 3: Google Scopes & OAuth Authorization */}
          <section id="google-scopes" style={{ marginBottom: '36px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#fef3c7', display: 'grid', placeItems: 'center', color: '#b45309' }}>
                <KeyRound size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                3. Google OAuth Scopes and Permissions
              </h2>
            </div>

            <p style={{ fontSize: '14px', color: '#475569', marginBottom: '14px' }}>
              Marketing Command uses the standard <strong>Google OAuth 2.0 authorization framework</strong>. We request strictly <strong>read-only scopes</strong> necessary for retrieving analytics and search visibility:
            </p>

            <div style={{ overflowX: 'auto', marginBottom: '16px' }}>
              <table
                style={{
                  width: '100%',
                  borderCollapse: 'collapse',
                  fontSize: '13px',
                  textAlign: 'left',
                }}
              >
                <thead>
                  <tr style={{ background: '#f8fafc', borderBottom: '2px solid #e2e8f0' }}>
                    <th style={{ padding: '10px 12px', color: '#334155', fontWeight: '700' }}>OAuth Scope URI</th>
                    <th style={{ padding: '10px 12px', color: '#334155', fontWeight: '700' }}>Permission Level</th>
                    <th style={{ padding: '10px 12px', color: '#334155', fontWeight: '700' }}>Purpose &amp; Application Use</th>
                  </tr>
                </thead>
                <tbody>
                  <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '10px 12px', fontFamily: 'monospace', color: '#0369a1', fontWeight: '600' }}>
                      .../auth/analytics.readonly
                    </td>
                    <td style={{ padding: '10px 12px', color: '#15803d', fontWeight: '600' }}>Read-only</td>
                    <td style={{ padding: '10px 12px', color: '#475569' }}>
                      View Google Analytics 4 accounts, properties, traffic reports, and visitor engagement metrics.
                    </td>
                  </tr>
                  <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '10px 12px', fontFamily: 'monospace', color: '#0369a1', fontWeight: '600' }}>
                      .../auth/webmasters.readonly
                    </td>
                    <td style={{ padding: '10px 12px', color: '#15803d', fontWeight: '600' }}>Read-only</td>
                    <td style={{ padding: '10px 12px', color: '#475569' }}>
                      View verified Search Console sites, search impressions, visitor clicks, CTR, position, and search queries.
                    </td>
                  </tr>
                  <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '10px 12px', fontFamily: 'monospace', color: '#0369a1', fontWeight: '600' }}>
                      .../auth/userinfo.email
                    </td>
                    <td style={{ padding: '10px 12px', color: '#15803d', fontWeight: '600' }}>Read-only</td>
                    <td style={{ padding: '10px 12px', color: '#475569' }}>
                      Verify the Google account identity connecting the property to associate it with the correct workspace.
                    </td>
                  </tr>
                  <tr>
                    <td style={{ padding: '10px 12px', fontFamily: 'monospace', color: '#0369a1', fontWeight: '600' }}>
                      .../auth/userinfo.profile
                    </td>
                    <td style={{ padding: '10px 12px', color: '#15803d', fontWeight: '600' }}>Read-only</td>
                    <td style={{ padding: '10px 12px', color: '#475569' }}>
                      Display connecting user profile name in the connection confirmation modal.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              style={{
                background: '#f8fafc',
                borderLeft: '4px solid #0284c7',
                padding: '12px 16px',
                borderRadius: '0 8px 8px 0',
                fontSize: '13px',
                color: '#334155',
              }}
            >
              <strong>Important:</strong> Marketing Command <em>never</em> requests write, edit, or administrative access to your Google Analytics accounts or Search Console properties. We cannot modify your tracking tags, alter your data filters, or delete your properties.
            </div>
          </section>

          <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '30px 0' }} />

          {/* Section 4: OAuth Token Storage, Encryption & Security */}
          <section id="token-security" style={{ marginBottom: '36px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#f5f3ff', display: 'grid', placeItems: 'center', color: '#7c3aed' }}>
                <Lock size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                4. Token Storage, Encryption, and Security
              </h2>
            </div>

            <p style={{ fontSize: '14px', color: '#475569', marginBottom: '14px' }}>
              We enforce rigorous security controls to safeguard authorization tokens and prevent credential exposure:
            </p>

            <ul style={{ margin: '0 0 16px', paddingLeft: '22px', fontSize: '14px', color: '#475569', display: 'grid', gap: '10px' }}>
              <li>
                <strong>Encryption at Rest:</strong> All Google OAuth refresh tokens are encrypted at rest in our secure database using industry-standard <code>AES-256-CBC</code> encryption through Laravel&apos;s cryptographic service. Tokens are never stored in plaintext.
              </li>
              <li>
                <strong>Ephemeral Access Tokens:</strong> Short-lived access tokens (valid for up to 3,600 seconds) are cached ephemerally with strict TTLs and safety buffers to make authorized API calls. When an access token expires, a fresh token is requested directly from Google&apos;s OAuth 2.0 token endpoint.
              </li>
              <li>
                <strong>Zero Client-Side Token Exposure:</strong> Sensitive refresh tokens are strictly hidden from all API response payloads (<code>$hidden</code> model attributes). Client-side browsers and front-end scripts never receive or store raw Google OAuth refresh tokens.
              </li>
              <li>
                <strong>No Password Storage:</strong> We never request, capture, or store your Google account password. All authentication occurs through Google&apos;s official OAuth consent screens.
              </li>
              <li>
                <strong>TLS Transmission Encryption:</strong> All communications between your browser, our servers, and Google APIs are encrypted in transit via Transport Layer Security (TLS 1.3 / HTTPS).
              </li>
            </ul>
          </section>

          <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '30px 0' }} />

          {/* Section 5: Data Sharing, Selling & Retention */}
          <section id="data-sharing" style={{ marginBottom: '36px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#ecfdf5', display: 'grid', placeItems: 'center', color: '#047857' }}>
                <Share2 size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                5. Data Sharing, Selling, and Retention
              </h2>
            </div>

            <div style={{ display: 'grid', gap: '14px', fontSize: '14px', color: '#475569' }}>
              <p style={{ margin: 0 }}>
                <strong>No Selling of Data:</strong> We do not sell, rent, monetize, or trade any personal data, analytics reports, or Google API data to third parties, data aggregators, or advertisers under any circumstances.
              </p>
              <p style={{ margin: 0 }}>
                <strong>No Artificial Intelligence Training:</strong> We do not use Google user data or customer marketing metrics to train, develop, or improve artificial intelligence (AI) or machine learning (ML) models.
              </p>
              <p style={{ margin: 0 }}>
                <strong>Internal Workspace Scope:</strong> Data retrieved from your Google account is scoped strictly to the client workspace you authorize. Users outside your authorized workspace cannot access or view your metrics.
              </p>
              <p style={{ margin: 0 }}>
                <strong>Retention Period:</strong> Analytics metrics displayed in the dashboard are queried on-demand and cached temporarily (e.g., 5 to 30 minutes) to deliver high performance without overloading external APIs. Tokens remain active only for as long as your workspace maintains the connection.
              </p>
            </div>
          </section>

          <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '30px 0' }} />

          {/* Section 6: Revoking Access and Data Deletion */}
          <section id="revocation-deletion" style={{ marginBottom: '36px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#fee2e2', display: 'grid', placeItems: 'center', color: '#dc2626' }}>
                <Trash2 size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                6. How to Revoke Access and Delete Data
              </h2>
            </div>

            <p style={{ fontSize: '14px', color: '#475569', marginBottom: '14px' }}>
              You retain full control over your Google account authorization and can disconnect or delete your credentials at any time:
            </p>

            <div style={{ display: 'grid', gap: '14px' }}>
              {/* Option 1: In-App Disconnection */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <strong style={{ fontSize: '14px', color: '#0f172a', display: 'block', marginBottom: '4px' }}>
                  Option A: Disconnect from Marketing Command
                </strong>
                <p style={{ margin: '0 0 8px', fontSize: '13px', color: '#475569' }}>
                  In your dashboard, navigate to <strong>API Connections</strong> (<code>/integrations</code>). Locate <strong>Google Analytics</strong> or <strong>Google Search Console</strong> and click <strong>Disconnect</strong>. This immediately removes your connection, clears active tokens from server cache, and marks the integration as disconnected.
                </p>
              </div>

              {/* Option 2: Google Account Revocation */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <strong style={{ fontSize: '14px', color: '#0f172a', display: 'block', marginBottom: '4px' }}>
                  Option B: Revoke Access via Google Account Settings
                </strong>
                <p style={{ margin: '0 0 10px', fontSize: '13px', color: '#475569' }}>
                  You can revoke Marketing Command&apos;s access directly from your Google Account at any time without logging into our platform:
                </p>
                <a
                  href="https://myaccount.google.com/permissions"
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '6px',
                    padding: '8px 14px',
                    borderRadius: '6px',
                    background: '#0284c7',
                    color: '#ffffff',
                    fontSize: '12.5px',
                    fontWeight: '600',
                    textDecoration: 'none',
                  }}
                >
                  <ExternalLink size={13} /> Manage Google Account Permissions
                </a>
              </div>

              {/* Option 3: Complete Data Deletion Request */}
              <div style={{ padding: '16px', borderRadius: '10px', background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                <strong style={{ fontSize: '14px', color: '#0f172a', display: 'block', marginBottom: '4px' }}>
                  Option C: Complete Workspace Data Deletion Request
                </strong>
                <p style={{ margin: 0, fontSize: '13px', color: '#475569' }}>
                  If you wish to delete your entire workspace, user account, and all stored integration records, contact our privacy team at <strong>privacy@redmindtechnologies.com</strong>. We will permanently delete your records from all active databases and caches within thirty (30) days of verification.
                </p>
              </div>
            </div>
          </section>

          <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '30px 0' }} />

          {/* Section 7: Contact Information */}
          <section id="contact" style={{ marginBottom: '10px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
              <div style={{ width: '30px', height: '30px', borderRadius: '8px', background: '#eff6ff', display: 'grid', placeItems: 'center', color: '#2457e6' }}>
                <Mail size={16} />
              </div>
              <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                7. Contact Information
              </h2>
            </div>

            <p style={{ fontSize: '14px', color: '#475569', marginBottom: '16px' }}>
              If you have any questions, concerns, or deletion requests regarding this Privacy Policy or our Google OAuth practices, please reach out to our team:
            </p>

            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
                gap: '16px',
                padding: '18px 20px',
                borderRadius: '12px',
                background: '#f8fafc',
                border: '1px solid #e2e8f0',
              }}
            >
              <div>
                <strong style={{ fontSize: '13px', color: '#0f172a', display: 'block' }}>Organization</strong>
                <span style={{ fontSize: '14px', color: '#475569' }}>Redmind Technologies</span>
              </div>
              <div>
                <strong style={{ fontSize: '13px', color: '#0f172a', display: 'block' }}>Application</strong>
                <span style={{ fontSize: '14px', color: '#475569' }}>Marketing Command</span>
              </div>
              <div>
                <strong style={{ fontSize: '13px', color: '#0f172a', display: 'block' }}>Privacy Email</strong>
                <a href="mailto:privacy@redmindtechnologies.com" style={{ fontSize: '14px', color: '#2457e6', textDecoration: 'none', fontWeight: '600' }}>
                  privacy@redmindtechnologies.com
                </a>
              </div>
              <div>
                <strong style={{ fontSize: '13px', color: '#0f172a', display: 'block' }}>Official Website</strong>
                <a href="https://redmindtechnologies.com" target="_blank" rel="noopener noreferrer" style={{ fontSize: '14px', color: '#2457e6', textDecoration: 'none', fontWeight: '600' }}>
                  https://redmindtechnologies.com
                </a>
              </div>
            </div>
          </section>
        </div>
      </main>

      {/* 4. Footer */}
      <footer
        style={{
          borderTop: '1px solid #e2e8f0',
          background: '#ffffff',
          padding: '24px',
          textAlign: 'center',
          fontSize: '13px',
          color: '#64748b',
        }}
      >
        <div style={{ maxWidth: '1140px', margin: '0 auto', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' }}>
          <div>
            &copy; {new Date().getFullYear()} Redmind Technologies. All rights reserved. Marketing Command Operations Dashboard.
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
            <Link to="/privacy-policy" style={{ color: '#2457e6', textDecoration: 'none', fontWeight: '600' }}>
              Privacy Policy
            </Link>
            <span>•</span>
            <Link to="/login" style={{ color: '#64748b', textDecoration: 'none' }}>
              Sign In
            </Link>
            <span>•</span>
            <a
              href="https://myaccount.google.com/permissions"
              target="_blank"
              rel="noopener noreferrer"
              style={{ color: '#64748b', textDecoration: 'none' }}
            >
              Google Account Permissions
            </a>
          </div>
        </div>
      </footer>
    </div>
  );
}
