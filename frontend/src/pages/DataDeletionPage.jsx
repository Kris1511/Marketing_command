import React, { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Shield, Trash2, Mail, CheckCircle2, AlertCircle, Loader2, Search } from 'lucide-react';
import axiosInstance from '../api/axiosInstance';

const cardStyle = {
  background: '#ffffff',
  borderRadius: '16px',
  border: '1px solid #e2e8f0',
  padding: '28px 32px',
  boxShadow: '0 2px 8px rgba(0,0,0,0.04)',
  marginBottom: '20px',
};

const headingStyle = { margin: '0 0 10px', fontSize: '18px', fontWeight: 700, color: '#0f172a' };
const listStyle = { margin: '8px 0 0', paddingLeft: '20px', color: '#334155', fontSize: '14.5px' };

export default function DataDeletionPage() {
  const [searchParams] = useSearchParams();
  const initialCode = (searchParams.get('code') || '').trim();

  const [code, setCode] = useState(initialCode);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const checkStatus = async (value) => {
    const trimmed = (value || '').trim();
    if (!trimmed) return;
    setLoading(true);
    setError('');
    setResult(null);
    try {
      const res = await axiosInstance.get(`/facebook/data-deletion/status/${encodeURIComponent(trimmed)}`);
      setResult(res.data?.data || null);
    } catch (err) {
      setError(
        err.response?.status === 404
          ? 'We could not find a deletion request with that confirmation code. Please check the code and try again.'
          : 'The status could not be loaded right now. Please try again later.'
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (initialCode) checkStatus(initialCode);
    // Only look up the code that arrived in the URL once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
      <header style={{ background: '#ffffff', borderBottom: '1px solid #e2e8f0', padding: '14px 24px' }}>
        <div style={{ maxWidth: '860px', margin: '0 auto', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', fontWeight: 700 }}>
            <Shield size={20} color="#2457e6" /> Marketing Command
          </div>
          <Link to="/privacy-policy" style={{ fontSize: '13px', color: '#2457e6', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <ArrowLeft size={14} /> Privacy Policy
          </Link>
        </div>
      </header>

      <main style={{ maxWidth: '860px', margin: '0 auto', padding: '32px 24px 64px' }}>
        <h1 style={{ fontSize: '28px', fontWeight: 800, margin: '0 0 8px', color: '#0f172a' }}>Facebook Data Deletion Instructions</h1>
        <p style={{ margin: '0 0 24px', color: '#475569' }}>
          When you sign in to Marketing Command with Facebook, we store the access needed to manage the Facebook Pages and linked Instagram
          accounts you chose to connect. You can remove that access and ask us to delete the data linked to your Facebook account at any time.
        </p>

        <section style={cardStyle}>
          <h2 style={headingStyle}>Option 1: Remove the app from Facebook (automatic)</h2>
          <ol style={listStyle}>
            <li>Open Facebook and go to <strong>Settings &amp; privacy &rarr; Settings</strong>.</li>
            <li>Open <strong>Apps and Websites</strong> (shown as <strong>Business Integrations</strong> in some versions).</li>
            <li>Find <strong>Cortex CRM</strong> (the Facebook app used by Marketing Command) in the list of active apps and select it.</li>
            <li>Click <strong>Remove</strong> and confirm.</li>
          </ol>
          <p style={{ margin: '12px 0 0', fontSize: '14px', color: '#475569' }}>
            Facebook then sends us a data deletion request and we delete the Facebook connection data described below. Facebook shows you a
            link to this page together with a <strong>confirmation code</strong>; use it in the status check at the bottom of this page.
          </p>
        </section>

        <section style={cardStyle}>
          <h2 style={headingStyle}>Option 2: Disconnect inside Marketing Command</h2>
          <p style={{ margin: 0, fontSize: '14.5px', color: '#334155' }}>
            Workspace administrators can sign in, open <strong>API Connections</strong>, and click <strong>Disconnect</strong> on Facebook or
            Instagram. This stops all access immediately. To also have the stored connection records erased, use Option 1 or Option 3.
          </p>
        </section>

        <section style={cardStyle}>
          <h2 style={{ ...headingStyle, display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Mail size={18} /> Option 3: Ask us to delete your data
          </h2>
          <p style={{ margin: 0, fontSize: '14.5px', color: '#334155' }}>
            Email <strong>privacy@redmindtechnologies.com</strong> with the subject &ldquo;Facebook data deletion request&rdquo;. Include your Facebook
            profile name or link and the name of the workspace you connected. After we verify the request we will delete your records within
            thirty (30) days, in line with our <Link to="/privacy-policy" style={{ color: '#2457e6' }}>Privacy Policy</Link>. Use this option if you
            connected Facebook a long time ago and Option 1 reports that nothing was found.
          </p>
        </section>

        <section style={cardStyle}>
          <h2 style={{ ...headingStyle, display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Trash2 size={18} /> What is deleted and what is kept
          </h2>
          <p style={{ margin: '0 0 4px', fontSize: '14.5px', fontWeight: 600 }}>Deleted when you use Option 1 or Option 3:</p>
          <ul style={listStyle}>
            <li>The Facebook Page and Instagram connections created by your Facebook login, including their stored access tokens.</li>
            <li>The link between your Facebook account and Marketing Command.</li>
          </ul>
          <p style={{ margin: '14px 0 4px', fontSize: '14.5px', fontWeight: 600 }}>Not deleted by an automatic request:</p>
          <ul style={listStyle}>
            <li>Client workspace data that belongs to your organisation, such as leads, reports and content created in Marketing Command, and other team members&rsquo; connections.</li>
            <li>Anything you published to your own Facebook Page or Instagram account. That content lives on Facebook and is managed there.</li>
          </ul>
          <p style={{ margin: '12px 0 0', fontSize: '14px', color: '#475569' }}>
            To delete your whole workspace and user account, use Option 3 and say so in your email.
          </p>
        </section>

        <section style={cardStyle}>
          <h2 style={{ ...headingStyle, display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Search size={18} /> Check the status of a request
          </h2>
          <form
            onSubmit={(event) => {
              event.preventDefault();
              checkStatus(code);
            }}
            style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}
          >
            <input
              type="text"
              value={code}
              onChange={(event) => setCode(event.target.value)}
              placeholder="Confirmation code"
              aria-label="Confirmation code"
              maxLength={40}
              style={{ flex: '1 1 260px', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: '8px', fontSize: '14px' }}
            />
            <button
              type="submit"
              disabled={loading || !code.trim()}
              style={{ padding: '10px 18px', borderRadius: '8px', border: 'none', background: '#2457e6', color: '#fff', fontWeight: 600, cursor: 'pointer', opacity: loading || !code.trim() ? 0.6 : 1 }}
            >
              {loading ? 'Checking…' : 'Check status'}
            </button>
          </form>

          {loading && (
            <p style={{ marginTop: '14px', color: '#64748b', display: 'flex', alignItems: 'center', gap: '8px' }}>
              <Loader2 size={16} /> Looking up your request…
            </p>
          )}

          {error && (
            <div role="alert" style={{ marginTop: '14px', padding: '12px 14px', borderRadius: '8px', background: '#fef2f2', color: '#b91c1c', fontSize: '14px', display: 'flex', gap: '8px' }}>
              <AlertCircle size={18} style={{ flexShrink: 0, marginTop: '2px' }} /> <span>{error}</span>
            </div>
          )}

          {result && (
            <div style={{ marginTop: '14px', padding: '14px 16px', borderRadius: '8px', background: result.status === 'completed' ? '#f0fdf4' : '#fffbeb', border: '1px solid #e2e8f0', fontSize: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 700, color: result.status === 'completed' ? '#15803d' : '#b45309' }}>
                <CheckCircle2 size={18} /> {result.status === 'completed' ? 'Completed' : 'In progress'}
              </div>
              <p style={{ margin: '8px 0 0', color: '#334155' }}>{result.message}</p>
              <p style={{ margin: '8px 0 0', color: '#64748b', fontSize: '13px' }}>
                Confirmation code: <strong>{result.confirmation_code}</strong>
                {result.completed_at ? ` · Completed ${new Date(result.completed_at).toLocaleString()}` : ''}
              </p>
            </div>
          )}
        </section>
      </main>
    </div>
  );
}
