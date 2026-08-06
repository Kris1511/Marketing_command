import React, { useState } from 'react';

export default function PublishingPage() {
  const [postTitle, setPostTitle] = useState('');
  const [postCaption, setPostCaption] = useState('');
  const [postHashtags, setPostHashtags] = useState('');
  const [postCTA, setPostCTA] = useState('Learn More');
  const [platforms, setPlatforms] = useState(['Facebook', 'Instagram']);

  const handlePlatformChange = (platform) => {
    setPlatforms((prev) =>
      prev.includes(platform) ? prev.filter((p) => p !== platform) : [...prev, platform]
    );
  };

  return (
    <div>
      <div className="section-head">
        <div>
          <h2>Content publishing</h2>
          <p>Create once, choose channels, and publish immediately or schedule for later.</p>
        </div>
        <div className="toolbar">
          <button type="button" className="btn btn-secondary">Save draft</button>
          <button type="button" className="btn btn-primary">Publish / Schedule</button>
        </div>
      </div>

      <div className="publisher-layout">
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Create content</h3>
              <p>Plain-language form suitable for daily marketing operations</p>
            </div>
            <span className="pill info">Auto-saved locally</span>
          </div>

          <div className="form-grid">
            <div className="form-field full">
              <label className="form-label">Where should this content be posted?</label>
              <div className="check-grid">
                {['Facebook', 'Instagram', 'YouTube', 'LinkedIn', 'X (Twitter)'].map((plat) => (
                  <label key={plat} className="check-card">
                    <input
                      type="checkbox"
                      value={plat}
                      checked={platforms.includes(plat)}
                      onChange={() => handlePlatformChange(plat)}
                    />
                    {' '}{plat}
                  </label>
                ))}
              </div>
            </div>

            <div className="form-field full">
              <label htmlFor="postTitle" className="form-label">Internal content title</label>
              <input
                id="postTitle"
                className="input"
                placeholder="Example: Monthly service update"
                value={postTitle}
                onChange={(e) => setPostTitle(e.target.value)}
              />
              <div className="form-help">Used by your team to identify the post. It is not always visible to customers.</div>
            </div>

            <div className="form-field full">
              <label htmlFor="postCaption" className="form-label">Caption / post message</label>
              <textarea
                id="postCaption"
                className="textarea"
                placeholder="Write the message customers should see"
                value={postCaption}
                onChange={(e) => setPostCaption(e.target.value)}
              />
            </div>

            <div className="form-field">
              <label htmlFor="postHashtags" className="form-label">Hashtags</label>
              <input
                id="postHashtags"
                className="input"
                placeholder="#YourBrand #Campaign"
                value={postHashtags}
                onChange={(e) => setPostHashtags(e.target.value)}
              />
            </div>

            <div className="form-field">
              <label htmlFor="postCTA" className="form-label">Call-to-action</label>
              <select
                id="postCTA"
                className="select"
                value={postCTA}
                onChange={(e) => setPostCTA(e.target.value)}
              >
                <option>Learn More</option>
                <option>Book Now</option>
                <option>Contact Us</option>
                <option>Send Message</option>
                <option>No CTA</option>
              </select>
            </div>

            <div className="form-field full">
              <label className="form-label">Image or video</label>
              <div className="media-drop">
                <strong>Drop media here or click to choose</strong>
                <div className="form-help">Images, videos, multiple media, YouTube thumbnails</div>
                <input type="file" accept="image/*,video/*" multiple style={{ marginTop: '12px', maxWidth: '100%' }} />
              </div>
            </div>

            <div className="form-field">
              <label htmlFor="publishType" className="form-label">When should it go live?</label>
              <select id="publishType" className="select">
                <option value="schedule">Schedule for later</option>
                <option value="now">Publish immediately</option>
                <option value="draft">Save as draft</option>
              </select>
            </div>

            <div className="form-field">
              <label htmlFor="scheduleAt" className="form-label">Scheduled date and time</label>
              <input type="datetime-local" id="scheduleAt" className="input" />
            </div>

            <div className="form-field full">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px' }}>
                <span className="form-help">Platform-specific fields can be edited after selecting YouTube.</span>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <button type="button" className="btn btn-secondary">Save draft</button>
                  <button type="button" className="btn btn-primary">Publish / Schedule</button>
                </div>
              </div>
            </div>
          </div>
        </section>

        <aside className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Live preview</h3>
              <p>How the content may appear on social media</p>
            </div>
            <span className="pill">Instagram</span>
          </div>
          <div className="preview-phone">
            <div className="preview-top"></div>
            <div className="preview-body">
              <div className="preview-profile">
                <div className="initial">RM</div>
                <div>
                  <strong style={{ fontSize: '12px' }}>business_account</strong>
                  <div className="muted" style={{ fontSize: '10px' }}>Preview</div>
                </div>
              </div>
              <div className="preview-image">Media preview</div>
              <div className="preview-caption">
                {postCaption || 'Your caption preview will appear here...'}
              </div>
              <div className="preview-tags">{postHashtags}</div>
              <button className="btn btn-sm btn-primary btn-block" type="button" style={{ marginTop: '12px' }}>
                {postCTA}
              </button>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}
