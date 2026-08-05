import React from 'react';

export default function ReportsPage() {
  return (
    <div>
      <div className="section-head">
        <div>
          <h2>Performance Reports & Analytics</h2>
          <p>Generate, schedule, and export comprehensive client marketing reports.</p>
        </div>
        <button type="button" className="btn btn-primary" onClick={() => window.print()}>
          🖨 Export PDF Report
        </button>
      </div>

      <div className="report-hero">
        <div className="report-summary">
          <h3>Monthly Marketing ROI Summary</h3>
          <p>Overall performance across Meta Ads, Google Ads, and Organic Social.</p>
          <div className="report-summary-grid">
            <div className="report-stat">
              <strong>$84,500</strong>
              <span>Attributed Revenue</span>
            </div>
            <div className="report-stat">
              <strong>3.8x</strong>
              <span>Overall ROAS</span>
            </div>
            <div className="report-stat">
              <strong>342</strong>
              <span>Qualified Leads</span>
            </div>
          </div>
        </div>

        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Key Strategic Insights</h3>
              <p>Automated performance highlights</p>
            </div>
          </div>
          <div className="insight-list">
            <div className="insight-item">
              <div className="insight-mark">✓</div>
              <div>
                <strong>Meta Facebook Lead Ads CPA dropped 18%</strong>
                <span>Lead cost decreased from $28.50 to $23.40 following copy optimization.</span>
              </div>
            </div>
            <div className="insight-item">
              <div className="insight-mark">✓</div>
              <div>
                <strong>Google Search Ads conversion rate reached 4.2%</strong>
                <span>High-intent buyer keywords delivered 120 new booked calls.</span>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
