import React, { useState, useEffect, useRef } from 'react';
import {
  FileText,
  X,
  Printer,
  Mail,
  Download,
  CheckCircle2,
  AlertCircle,
  TrendingUp,
  Users,
  Eye,
  Heart,
  MessageSquare,
  Sparkles,
  Loader2,
  Calendar,
} from 'lucide-react';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';

export default function ExecutiveReportModal({ isOpen, onClose, workspaceId }) {
  const { selectedWorkspace } = useWorkspace();
  const [range, setRange] = useState('last_7_days');
  const [reportData, setReportData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [sendingEmail, setSendingEmail] = useState(false);
  const [emailStatus, setEmailStatus] = useState(null);
  const [error, setError] = useState('');
  const printRef = useRef(null);

  const effectiveWsId = workspaceId || selectedWorkspace?.id || 1;

  useEffect(() => {
    if (!isOpen) return;

    const fetchReport = async () => {
      setLoading(true);
      setError('');
      setEmailStatus(null);
      try {
        const res = await axiosInstance.get('/reports/pdf-preview', {
          params: { workspace_id: effectiveWsId, range },
        });
        if (res.data?.success && res.data?.report) {
          setReportData(res.data.report);
        }
      } catch (err) {
        setError('Failed to generate report data.');
      } finally {
        setLoading(false);
      }
    };

    fetchReport();
  }, [isOpen, effectiveWsId, range]);

  if (!isOpen) return null;

  const handlePrint = () => {
    window.print();
  };

  const handleSendEmail = async () => {
    if (!reportData?.contact_email) {
      alert('No primary contact email found for this client.');
      return;
    }

    setSendingEmail(true);
    setEmailStatus(null);
    try {
      const res = await axiosInstance.post('/reports/send-client-email', {
        workspace_id: effectiveWsId,
        email: reportData.contact_email,
      });
      if (res.data?.success) {
        setEmailStatus({ success: true, message: res.data.message });
      }
    } catch (err) {
      setEmailStatus({ success: false, message: 'Failed to send email report.' });
    } finally {
      setSendingEmail(false);
    }
  };

  return (
    <div
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 9999,
        background: 'rgba(15, 23, 42, 0.7)',
        backdropFilter: 'blur(4px)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '16px',
      }}
      onClick={onClose}
    >
      <div
        style={{
          background: '#ffffff',
          borderRadius: '16px',
          width: '100%',
          maxWidth: '840px',
          maxHeight: '92vh',
          display: 'flex',
          flexDirection: 'column',
          boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
          overflow: 'hidden',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header / Actions Bar */}
        <div
          style={{
            padding: '16px 24px',
            borderBottom: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: '#f8fafc',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '8px',
                background: '#2563eb',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: '#ffffff',
              }}
            >
              <FileText size={20} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
                Executive Performance Report
              </h3>
              <p style={{ margin: 0, fontSize: '12px', color: '#64748b' }}>
                Automated client-ready summary & analytics
              </p>
            </div>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              style={{
                padding: '6px 10px',
                borderRadius: '8px',
                border: '1px solid #cbd5e1',
                fontSize: '12.5px',
                background: '#ffffff',
              }}
            >
              <option value="last_7_days">Last 7 Days</option>
              <option value="this_month">This Month</option>
            </select>

            <button
              onClick={handlePrint}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                padding: '7px 14px',
                borderRadius: '8px',
                border: '1px solid #cbd5e1',
                background: '#ffffff',
                color: '#334155',
                fontSize: '12.5px',
                fontWeight: '600',
                cursor: 'pointer',
              }}
            >
              <Printer size={15} /> Print / Save PDF
            </button>

            <button
              onClick={handleSendEmail}
              disabled={sendingEmail}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                padding: '7px 14px',
                borderRadius: '8px',
                border: 'none',
                background: '#2563eb',
                color: '#ffffff',
                fontSize: '12.5px',
                fontWeight: '700',
                cursor: sendingEmail ? 'not-allowed' : 'pointer',
              }}
            >
              {sendingEmail ? <Loader2 size={15} className="animate-spin" /> : <Mail size={15} />}
              Email to Client
            </button>

            <button
              onClick={onClose}
              style={{
                background: 'transparent',
                border: 'none',
                color: '#64748b',
                cursor: 'pointer',
                padding: '4px',
              }}
            >
              <X size={20} />
            </button>
          </div>
        </div>

        {/* Email feedback notification */}
        {emailStatus && (
          <div
            style={{
              padding: '10px 24px',
              background: emailStatus.success ? '#f0fdf4' : '#fef2f2',
              color: emailStatus.success ? '#15803d' : '#b91c1c',
              fontSize: '13px',
              fontWeight: '600',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              borderBottom: '1px solid #e2e8f0',
            }}
          >
            {emailStatus.success ? <CheckCircle2 size={16} /> : <AlertCircle size={16} />}
            {emailStatus.message}
          </div>
        )}

        {/* Printable Report Document */}
        <div style={{ flex: 1, overflowY: 'auto', padding: '30px', background: '#f1f5f9' }}>
          {loading ? (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '60px' }}>
              <Loader2 size={32} className="animate-spin" color="#2563eb" />
            </div>
          ) : reportData ? (
            <div
              ref={printRef}
              style={{
                background: '#ffffff',
                borderRadius: '12px',
                padding: '36px',
                boxShadow: '0 4px 12px rgba(0,0,0,0.06)',
                border: '1px solid #e2e8f0',
                maxWidth: '740px',
                margin: '0 auto',
              }}
            >
              {/* Report Header */}
              <div
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'flex-start',
                  borderBottom: '2px solid #2563eb',
                  paddingBottom: '20px',
                  marginBottom: '24px',
                }}
              >
                <div>
                  <span
                    style={{
                      fontSize: '11px',
                      fontWeight: '800',
                      textTransform: 'uppercase',
                      letterSpacing: '0.1em',
                      color: '#2563eb',
                    }}
                  >
                    Executive Performance Summary
                  </span>
                  <h1 style={{ margin: '4px 0 0 0', fontSize: '24px', fontWeight: '800', color: '#0f172a' }}>
                    {reportData.workspace_name}
                  </h1>
                  <p style={{ margin: '4px 0 0 0', fontSize: '13px', color: '#64748b' }}>
                    Prepared for: {reportData.contact_name} ({reportData.contact_email})
                  </p>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ fontSize: '12px', fontWeight: '700', color: '#1e293b' }}>
                    Period: {reportData.period_dates}
                  </div>
                  <div style={{ fontSize: '11px', color: '#64748b', marginTop: '2px' }}>
                    Generated: {reportData.generated_at}
                  </div>
                </div>
              </div>

              {/* KPI Scorecard */}
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(4, 1fr)',
                  gap: '12px',
                  marginBottom: '28px',
                }}
              >
                {[
                  { label: 'Total Reach', value: reportData.total_reach.toLocaleString(), icon: Eye, color: '#2563eb', bg: '#eff6ff' },
                  { label: 'Engagement & Likes', value: reportData.total_likes.toLocaleString(), icon: Heart, color: '#ec4899', bg: '#fdf2f8' },
                  { label: 'Total Comments', value: reportData.total_comments.toLocaleString(), icon: MessageSquare, color: '#8b5cf6', bg: '#f5f3ff' },
                  { label: 'Leads Generated', value: reportData.total_leads.toLocaleString(), icon: Users, color: '#16a34a', bg: '#f0fdf4' },
                ].map((kpi, idx) => {
                  const Icon = kpi.icon;
                  return (
                    <div
                      key={idx}
                      style={{
                        background: kpi.bg,
                        borderRadius: '10px',
                        padding: '14px',
                        border: '1px solid #e2e8f0',
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: '6px', color: kpi.color, marginBottom: '6px' }}>
                        <Icon size={16} />
                        <span style={{ fontSize: '11px', fontWeight: '700', textTransform: 'uppercase' }}>{kpi.label}</span>
                      </div>
                      <div style={{ fontSize: '20px', fontWeight: '800', color: '#0f172a' }}>
                        {kpi.value}
                      </div>
                    </div>
                  );
                })}
              </div>

              {/* Top Performing Content Section */}
              <div style={{ marginBottom: '28px' }}>
                <h3 style={{ fontSize: '15px', fontWeight: '700', color: '#0f172a', marginBottom: '12px' }}>
                  🔥 Top Performing Content
                </h3>
                {reportData.top_posts && reportData.top_posts.length > 0 ? (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {reportData.top_posts.map((post, pIdx) => (
                      <div
                        key={post.id || pIdx}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          padding: '12px 14px',
                          background: '#f8fafc',
                          borderRadius: '8px',
                          border: '1px solid #e2e8f0',
                        }}
                      >
                        <div style={{ flex: 1, marginRight: '16px' }}>
                          <span style={{ fontSize: '10.5px', fontWeight: '700', color: '#2563eb', textTransform: 'uppercase' }}>
                            #{pIdx + 1} • {post.post_type}
                          </span>
                          <p style={{ margin: '2px 0 0 0', fontSize: '12.5px', color: '#1e293b', fontWeight: '500' }}>
                            {post.content ? (post.content.length > 70 ? post.content.substring(0, 70) + '...' : post.content) : 'Published Content'}
                          </p>
                        </div>
                        <div style={{ display: 'flex', gap: '14px', textAlign: 'right' }}>
                          <div>
                            <div style={{ fontSize: '10px', color: '#64748b' }}>Reach</div>
                            <div style={{ fontSize: '13px', fontWeight: '700', color: '#0f172a' }}>{(post.reach_count || 0).toLocaleString()}</div>
                          </div>
                          <div>
                            <div style={{ fontSize: '10px', color: '#64748b' }}>Likes</div>
                            <div style={{ fontSize: '13px', fontWeight: '700', color: '#0f172a' }}>{(post.likes_count || 0).toLocaleString()}</div>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p style={{ fontSize: '12.5px', color: '#64748b', fontStyle: 'italic' }}>
                    No published posts recorded during this reporting window.
                  </p>
                )}
              </div>

              {/* Agency Footer Note */}
              <div
                style={{
                  borderTop: '1px solid #e2e8f0',
                  paddingTop: '16px',
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  fontSize: '11px',
                  color: '#94a3b8',
                }}
              >
                <span>Generated by Marketing Command Analytics Engine</span>
                <span>Confidential • Redmind Technologies</span>
              </div>
            </div>
          ) : (
            <p>No data available.</p>
          )}
        </div>
      </div>
    </div>
  );
}
