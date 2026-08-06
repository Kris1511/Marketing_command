import React, { useState } from 'react';
import {
  TrendingUp,
  Check,
  AlertTriangle,
  BookOpen,
  Heart,
  ArrowUpRight,
  UserPlus
} from 'lucide-react';

export default function ReportsPage() {
  const [platform, setPlatform] = useState('all');
  const [period, setPeriod] = useState('30');
  const [compareWith, setCompareWith] = useState('previous');

  const handleDownloadCsv = () => {
    const csvContent = 'data:text/csv;charset=utf-8,Platform,Metric,Value\nMeta,Reach,128400\nMeta,Leads,186\nGoogle,Conversions,42';
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', 'marketing_report.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>Reports & insights</h2>
          <p>Build clear client reports using simple filters and ready-made visuals.</p>
        </div>
        <div style={{ display: 'flex', gap: '10px' }}>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={handleDownloadCsv}
          >
            Download Excel/CSV
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => window.print()}
          >
            Download PDF / Print
          </button>
        </div>
      </div>

      {/* 2. Filter Controls Card */}
      <div className="panel mb-18" style={{ marginBottom: '20px' }}>
        <div className="form-grid" style={{ alignItems: 'end' }}>
          <div className="form-field">
            <label className="form-label">Platform</label>
            <select
              className="select"
              value={platform}
              onChange={(e) => setPlatform(e.target.value)}
            >
              <option value="all">All connected platforms</option>
              <option value="meta">Facebook & Instagram</option>
              <option value="google">Google Ads & Analytics</option>
              <option value="youtube">YouTube</option>
            </select>
          </div>

          <div className="form-field">
            <label className="form-label">Period</label>
            <select
              className="select"
              value={period}
              onChange={(e) => setPeriod(e.target.value)}
            >
              <option value="30">Last 30 days</option>
              <option value="7">Last 7 days</option>
              <option value="90">Last 90 days</option>
              <option value="ytd">Year to Date</option>
            </select>
          </div>

          <div className="form-field">
            <label className="form-label">Compare with</label>
            <select
              className="select"
              value={compareWith}
              onChange={(e) => setCompareWith(e.target.value)}
            >
              <option value="previous">Previous period</option>
              <option value="prev_year">Previous year</option>
              <option value="baseline">Custom baseline</option>
            </select>
          </div>

          <div className="form-field">
            <button
              type="button"
              className="btn btn-primary btn-block"
              style={{ height: '42px' }}
            >
              Generate report
            </button>
          </div>
        </div>
      </div>

      {/* 3. Hero Section (Aara Wellness Summary & Plain-language summary) */}
      <div className="report-hero">
        <div className="report-summary">
          <h3>Aara Wellness – Performance Summary</h3>
          <p>July 6 to August 4, 2026 • Compared with previous period</p>
          <div className="report-summary-grid">
            <div className="report-stat">
              <strong>128.4K</strong>
              <span>Total reach</span>
            </div>
            <div className="report-stat">
              <strong>186</strong>
              <span>Leads generated</span>
            </div>
            <div className="report-stat">
              <strong>12.4%</strong>
              <span>Conversion rate</span>
            </div>
          </div>
        </div>

        <section className="panel">
          <div className="panel-header" style={{ marginBottom: '14px' }}>
            <div className="panel-title">
              <h3>Plain-language summary</h3>
              <p>Ready to share with the client</p>
            </div>
          </div>

          <div className="insight-list">
            <div className="insight-item">
              <div className="insight-icon-box success"><TrendingUp size={16} /></div>
              <div>
                <strong>Reach improved strongly</strong>
                <div className="muted" style={{ fontSize: '12px', marginTop: '2px' }}>
                  More people discovered the brand than during the previous period.
                </div>
              </div>
            </div>

            <div className="insight-item">
              <div className="insight-icon-box success"><Check size={16} /></div>
              <div>
                <strong>Instagram generated the most engagement</strong>
                <div className="muted" style={{ fontSize: '12px', marginTop: '2px' }}>
                  Reels and carousel posts produced the best response.
                </div>
              </div>
            </div>

            <div className="insight-item">
              <div className="insight-icon-box warning"><AlertTriangle size={16} /></div>
              <div>
                <strong>Follow-up speed can improve</strong>
                <div className="muted" style={{ fontSize: '12px', marginTop: '2px' }}>
                  18 leads waited more than one business day for first contact.
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      {/* 4. Metric Cards Grid */}
      <div className="metric-grid mt-18">
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Impressions</span>
            <div className="metric-icon"><BookOpen size={20} /></div>
          </div>
          <div className="metric-value">220.9K</div>
          <div className="metric-foot">
            <span className="trend-up">+16.4%</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Engagement</span>
            <div className="metric-icon"><Heart size={20} /></div>
          </div>
          <div className="metric-value">7.8K</div>
          <div className="metric-foot">
            <span className="trend-up">+11.8%</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Website users</span>
            <div className="metric-icon"><ArrowUpRight size={20} /></div>
          </div>
          <div className="metric-value">18.4K</div>
          <div className="metric-foot">
            <span className="trend-up">+9.2%</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Followers</span>
            <div className="metric-icon"><UserPlus size={20} /></div>
          </div>
          <div className="metric-value">24.9K</div>
          <div className="metric-foot">
            <span className="trend-up">+6.8%</span> net audience growth
          </div>
        </div>
      </div>

      {/* 5. Channel Contribution & Lead Source Performance Grid */}
      <div className="grid-equal mt-18">
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Channel contribution</h3>
              <p>Share of total reach</p>
            </div>
          </div>

          <div className="report-progress-list">
            <div className="report-progress-row">
              <span className="report-progress-label">Instagram</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '42%' }}></div>
              </div>
              <span className="report-progress-val">42%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">Facebook</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '27%' }}></div>
              </div>
              <span className="report-progress-val">27%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">YouTube</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '18%' }}></div>
              </div>
              <span className="report-progress-val">18%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">Google</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '13%' }}></div>
              </div>
              <span className="report-progress-val">13%</span>
            </div>
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Lead source performance</h3>
              <p>Which sources created useful enquiries</p>
            </div>
          </div>

          <div className="report-progress-list">
            <div className="report-progress-row">
              <span className="report-progress-label">Facebook Ads</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '38%' }}></div>
              </div>
              <span className="report-progress-val">38%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">Website</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '26%' }}></div>
              </div>
              <span className="report-progress-val">26%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">Google Forms</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '18%' }}></div>
              </div>
              <span className="report-progress-val">18%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">Landing Pages</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill" style={{ width: '12%' }}></div>
              </div>
              <span className="report-progress-val">12%</span>
            </div>

            <div className="report-progress-row">
              <span className="report-progress-label">Manual</span>
              <div className="report-progress-bar">
                <div className="report-progress-fill success" style={{ width: '6%' }}></div>
              </div>
              <span className="report-progress-val">6%</span>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
