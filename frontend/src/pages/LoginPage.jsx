import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

export default function LoginPage() {
  const navigate = useNavigate();
  const { login, error: authError } = useAuth();

  const [email, setEmail] = useState('demo@example.com');
  const [password, setPassword] = useState('password123');
  const [loading, setLoading] = useState(false);
  const [localError, setLocalError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLocalError('');
    setLoading(true);

    const res = await login(email, password);
    setLoading(false);

    if (res.success) {
      navigate('/');
    } else {
      setLocalError(res.message || 'Invalid credentials');
    }
  };

  const fillCredentials = (demoEmail, demoPass) => {
    setEmail(demoEmail);
    setPassword(demoPass);
    setLocalError('');
  };

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: 'linear-gradient(135deg, #0f1f3d 0%, #172b52 100%)',
        padding: '20px',
        fontFamily: 'Inter, system-ui, sans-serif',
      }}
    >
      <div
        style={{
          width: '100%',
          maxWidth: '440px',
          background: '#ffffff',
          borderRadius: '20px',
          padding: '36px 32px',
          boxShadow: '0 20px 40px rgba(0, 0, 0, 0.2)',
        }}
      >
        {/* Brand Logo Header */}
        <div style={{ textAlign: 'center', marginBottom: '28px' }}>
          <div
            style={{
              width: '54px',
              height: '54px',
              borderRadius: '16px',
              background: '#2457e6',
              color: '#ffffff',
              display: 'inline-grid',
              placeItems: 'center',
              fontSize: '20px',
              fontWeight: '900',
              marginBottom: '12px',
            }}
          >
            RM
          </div>
          <h2 style={{ margin: '0', fontSize: '22px', fontWeight: '800', color: '#0f172a' }}>
            Marketing Command
          </h2>
          <p style={{ margin: '4px 0 0', fontSize: '13px', color: '#64748b' }}>
            Sign in to access your operations dashboard
          </p>
        </div>

        {/* Error Alert */}
        {(localError || authError) && (
          <div
            style={{
              background: '#fdecef',
              border: '1px solid #f87171',
              color: '#c23a4b',
              padding: '12px 14px',
              borderRadius: '10px',
              fontSize: '13px',
              marginBottom: '20px',
              fontWeight: '600',
            }}
          >
            ⚠️ {localError || authError}
          </div>
        )}

        {/* Login Form */}
        <form onSubmit={handleSubmit}>
          <div style={{ marginBottom: '18px' }}>
            <label
              htmlFor="loginEmail"
              style={{
                display: 'block',
                fontSize: '12px',
                fontWeight: '700',
                color: '#334155',
                marginBottom: '6px',
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
              }}
            >
              Email Address
            </label>
            <input
              id="loginEmail"
              type="email"
              placeholder="demo@example.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              style={{
                width: '100%',
                padding: '12px 14px',
                border: '1px solid #cbd5e1',
                borderRadius: '10px',
                fontSize: '14px',
                color: '#0f172a',
                outline: 'none',
                boxSizing: 'border-box',
              }}
            />
          </div>

          <div style={{ marginBottom: '24px' }}>
            <label
              htmlFor="loginPassword"
              style={{
                display: 'block',
                fontSize: '12px',
                fontWeight: '700',
                color: '#334155',
                marginBottom: '6px',
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
              }}
            >
              Password
            </label>
            <input
              id="loginPassword"
              type="password"
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              style={{
                width: '100%',
                padding: '12px 14px',
                border: '1px solid #cbd5e1',
                borderRadius: '10px',
                fontSize: '14px',
                color: '#0f172a',
                outline: 'none',
                boxSizing: 'border-box',
              }}
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            style={{
              width: '100%',
              padding: '14px',
              background: loading ? '#94a3b8' : '#2457e6',
              color: '#ffffff',
              border: 'none',
              borderRadius: '12px',
              fontSize: '15px',
              fontWeight: '700',
              cursor: loading ? 'not-allowed' : 'pointer',
              transition: 'background 0.2s ease',
            }}
          >
            {loading ? 'Signing in...' : 'Sign In'}
          </button>
        </form>

        {/* Demo Credentials Box */}
        <div
          style={{
            marginTop: '28px',
            padding: '14px 16px',
            background: '#f8fafc',
            border: '1px dashed #cbd5e1',
            borderRadius: '12px',
            fontSize: '12px',
            color: '#475569',
          }}
        >
          <strong style={{ color: '#0f172a', display: 'block', marginBottom: '8px' }}>
            🔑 Demo Credentials (Click to pre-fill):
          </strong>
          <div
            style={{
              display: 'flex',
              flexDirection: 'column',
              gap: '6px',
            }}
          >
            <button
              type="button"
              onClick={() => fillCredentials('demo@example.com', 'password123')}
              style={{
                textAlign: 'left',
                background: '#ffffff',
                border: '1px solid #cbd5e1',
                padding: '6px 10px',
                borderRadius: '6px',
                fontSize: '12px',
                cursor: 'pointer',
                color: '#2457e6',
                fontWeight: '600',
              }}
            >
              • Admin: demo@example.com / password123
            </button>

            <button
              type="button"
              onClick={() => fillCredentials('manager@example.com', 'password123')}
              style={{
                textAlign: 'left',
                background: '#ffffff',
                border: '1px solid #cbd5e1',
                padding: '6px 10px',
                borderRadius: '6px',
                fontSize: '12px',
                cursor: 'pointer',
                color: '#2457e6',
                fontWeight: '600',
              }}
            >
              • Manager: manager@example.com / password123
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
