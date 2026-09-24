import React, { useState } from 'react';
import {
  Sparkles,
  X,
  Copy,
  Check,
  ArrowRight,
  Loader2,
  Wand2,
  Hash,
  Smile,
  Globe,
  Share2,
} from 'lucide-react';
import axiosInstance from '../api/axiosInstance';

const TONES = [
  { id: 'engaging', label: '🔥 Engaging & Hook', desc: 'Grabs attention quickly' },
  { id: 'professional', label: '💼 Professional', desc: 'Polished & authoritative' },
  { id: 'promotional', label: '🎯 Promotional / Offer', desc: 'Drives sales & conversions' },
  { id: 'witty', label: '😄 Witty & Fun', desc: 'Humorous & relatable' },
  { id: 'storytelling', label: '📖 Storytelling', desc: 'Emotional & deep connect' },
];

const PLATFORMS = [
  { id: 'instagram', label: 'Instagram' },
  { id: 'facebook', label: 'Facebook' },
  { id: 'twitter', label: 'X (Twitter)' },
  { id: 'linkedin', label: 'LinkedIn' },
  { id: 'youtube', label: 'YouTube' },
];

export default function AiCaptionModal({
  isOpen,
  onClose,
  onApplyCaption,
  workspaceId,
  initialTopic = '',
}) {
  const [topic, setTopic] = useState(initialTopic);
  const [platform, setPlatform] = useState('instagram');
  const [tone, setTone] = useState('engaging');
  const [includeHashtags, setIncludeHashtags] = useState(true);
  const [includeEmojis, setIncludeEmojis] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [variations, setVariations] = useState([]);
  const [copiedIndex, setCopiedIndex] = useState(null);

  if (!isOpen) return null;

  const handleGenerate = async (e) => {
    e?.preventDefault();
    if (!topic.trim()) {
      setError('Please provide a topic or brief idea.');
      return;
    }

    setLoading(true);
    setError('');
    setVariations([]);

    try {
      const res = await axiosInstance.post('/ai/generate-caption', {
        topic: topic.trim(),
        platform,
        tone,
        include_hashtags: includeHashtags,
        include_emojis: includeEmojis,
        workspace_id: workspaceId,
      });

      if (res.data?.success && Array.isArray(res.data?.variations)) {
        setVariations(res.data.variations);
      } else {
        setError(res.data?.message || 'Failed to generate captions. Please try again.');
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to connect to AI generation service.');
    } finally {
      setLoading(false);
    }
  };

  const handleCopy = (text, index) => {
    navigator.clipboard.writeText(text);
    setCopiedIndex(index);
    setTimeout(() => setCopiedIndex(null), 2000);
  };

  const handleApply = (variation) => {
    if (onApplyCaption) {
      onApplyCaption({
        caption: variation.caption || '',
        hashtags: (variation.hashtags || []).join(' '),
        fullText: variation.full_text || variation.caption || '',
      });
    }
    onClose();
  };

  return (
    <div
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 9999,
        background: 'rgba(15, 23, 42, 0.65)',
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
          maxWidth: '720px',
          maxHeight: '90vh',
          display: 'flex',
          flexDirection: 'column',
          boxShadow: '0 20px 40px rgba(0,0,0,0.2)',
          overflow: 'hidden',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div
          style={{
            padding: '18px 24px',
            borderBottom: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: 'linear-gradient(135deg, #eff6ff 0%, #ffffff 100%)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '10px',
                background: 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: '#fff',
                boxShadow: '0 4px 12px rgba(37, 99, 235, 0.25)',
              }}
            >
              <Sparkles size={18} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '17px', fontWeight: '700', color: '#0f172a' }}>
                AI Caption & Hashtag Assistant
              </h3>
              <p style={{ margin: 0, fontSize: '12px', color: '#64748b' }}>
                Generate high-converting, platform-tailored copy in seconds
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            style={{
              background: 'transparent',
              border: 'none',
              cursor: 'pointer',
              color: '#64748b',
              padding: '6px',
              borderRadius: '8px',
            }}
          >
            <X size={20} />
          </button>
        </div>

        {/* Content Body */}
        <div style={{ padding: '20px 24px', overflowY: 'auto', flex: 1 }}>
          <form onSubmit={handleGenerate}>
            {/* Topic Input */}
            <div style={{ marginBottom: '16px' }}>
              <label style={{ display: 'block', fontWeight: '600', fontSize: '13px', color: '#334155', marginBottom: '6px' }}>
                What is your post about? *
              </label>
              <textarea
                value={topic}
                onChange={(e) => setTopic(e.target.value)}
                placeholder="e.g. Announcing 20% discount on all digital marketing audits this weekend only..."
                rows={2}
                style={{
                  width: '100%',
                  padding: '10px 12px',
                  borderRadius: '10px',
                  border: '1px solid #cbd5e1',
                  fontSize: '13.5px',
                  boxSizing: 'border-box',
                  resize: 'vertical',
                  fontFamily: 'inherit',
                }}
              />
            </div>

            {/* Platform and Tone Grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '14px', marginBottom: '16px' }}>
              {/* Platform */}
              <div>
                <label style={{ display: 'block', fontWeight: '600', fontSize: '12.5px', color: '#334155', marginBottom: '6px' }}>
                  Target Platform
                </label>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                  {PLATFORMS.map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      onClick={() => setPlatform(p.id)}
                      style={{
                        padding: '6px 12px',
                        borderRadius: '8px',
                        fontSize: '12px',
                        fontWeight: '600',
                        border: platform === p.id ? '1.5px solid #2563eb' : '1px solid #e2e8f0',
                        background: platform === p.id ? '#eff6ff' : '#f8fafc',
                        color: platform === p.id ? '#1d4ed8' : '#64748b',
                        cursor: 'pointer',
                      }}
                    >
                      {p.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Tone */}
              <div>
                <label style={{ display: 'block', fontWeight: '600', fontSize: '12.5px', color: '#334155', marginBottom: '6px' }}>
                  Tone of Voice
                </label>
                <select
                  value={tone}
                  onChange={(e) => setTone(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '8px 12px',
                    borderRadius: '8px',
                    border: '1px solid #cbd5e1',
                    fontSize: '13px',
                    background: '#ffffff',
                    fontFamily: 'inherit',
                  }}
                >
                  {TONES.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.label} — {t.desc}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* Toggles */}
            <div style={{ display: 'flex', gap: '20px', alignItems: 'center', marginBottom: '18px' }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12.5px', color: '#475569', cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={includeHashtags}
                  onChange={(e) => setIncludeHashtags(e.target.checked)}
                />
                <Hash size={14} color="#64748b" /> Include Hashtags
              </label>
              <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12.5px', color: '#475569', cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={includeEmojis}
                  onChange={(e) => setIncludeEmojis(e.target.checked)}
                />
                <Smile size={14} color="#64748b" /> Include Emojis
              </label>
            </div>

            {error && (
              <div style={{ background: '#fef2f2', color: '#dc2626', padding: '10px 14px', borderRadius: '8px', fontSize: '12.5px', marginBottom: '14px' }}>
                {error}
              </div>
            )}

            {/* Generate Button */}
            <button
              type="submit"
              disabled={loading || !topic.trim()}
              style={{
                width: '100%',
                padding: '11px',
                borderRadius: '10px',
                border: 'none',
                background: loading ? '#94a3b8' : 'linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)',
                color: '#ffffff',
                fontWeight: '700',
                fontSize: '14px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '8px',
                cursor: loading ? 'not-allowed' : 'pointer',
                boxShadow: '0 4px 12px rgba(37, 99, 235, 0.2)',
              }}
            >
              {loading ? (
                <>
                  <Loader2 size={16} className="animate-spin" /> Generating Variations...
                </>
              ) : (
                <>
                  <Wand2 size={16} /> Generate 3 AI Variations
                </>
              )}
            </button>
          </form>

          {/* Results List */}
          {variations.length > 0 && (
            <div style={{ marginTop: '24px' }}>
              <h4 style={{ fontSize: '13px', fontWeight: '700', color: '#475569', marginBottom: '12px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                Generated Variations:
              </h4>

              <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                {variations.map((item, idx) => (
                  <div
                    key={idx}
                    style={{
                      border: '1px solid #e2e8f0',
                      borderRadius: '12px',
                      padding: '16px',
                      background: '#f8fafc',
                      transition: 'border 0.2s ease',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                      <span style={{ fontSize: '12px', fontWeight: '700', color: '#2563eb', background: '#eff6ff', padding: '3px 8px', borderRadius: '6px' }}>
                        {item.label || `Option ${idx + 1}`}
                      </span>
                      <div style={{ display: 'flex', gap: '8px' }}>
                        <button
                          type="button"
                          onClick={() => handleCopy(item.full_text || item.caption, idx)}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '4px',
                            padding: '5px 10px',
                            borderRadius: '6px',
                            border: '1px solid #cbd5e1',
                            background: '#ffffff',
                            color: copiedIndex === idx ? '#16a34a' : '#475569',
                            fontSize: '12px',
                            fontWeight: '600',
                            cursor: 'pointer',
                          }}
                        >
                          {copiedIndex === idx ? <Check size={13} /> : <Copy size={13} />}
                          {copiedIndex === idx ? 'Copied' : 'Copy'}
                        </button>
                        <button
                          type="button"
                          onClick={() => handleApply(item)}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '4px',
                            padding: '5px 12px',
                            borderRadius: '6px',
                            border: 'none',
                            background: '#2563eb',
                            color: '#ffffff',
                            fontSize: '12px',
                            fontWeight: '700',
                            cursor: 'pointer',
                          }}
                        >
                          Use in Post <ArrowRight size={13} />
                        </button>
                      </div>
                    </div>

                    <p style={{ fontSize: '13px', color: '#1e293b', whiteSpace: 'pre-line', lineHeight: '1.5', margin: '0 0 10px 0' }}>
                      {item.caption}
                    </p>

                    {item.hashtags && item.hashtags.length > 0 && (
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                        {item.hashtags.map((tag, tIdx) => (
                          <span
                            key={tIdx}
                            style={{
                              fontSize: '11px',
                              fontWeight: '600',
                              color: '#3b82f6',
                              background: '#ffffff',
                              border: '1px solid #bfdbfe',
                              padding: '2px 6px',
                              borderRadius: '4px',
                            }}
                          >
                            {tag}
                          </span>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
