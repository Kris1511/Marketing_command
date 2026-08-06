import React, { createContext, useState, useEffect, useCallback } from 'react';
import axiosInstance from '../api/axiosInstance';

export const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(localStorage.getItem('auth_token'));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  // Verify stored token on initial app mount
  const checkAuth = useCallback(async () => {
    const storedToken = localStorage.getItem('auth_token');
    if (!storedToken) {
      setLoading(false);
      setUser(null);
      setToken(null);
      return;
    }

    try {
      const res = await axiosInstance.get('/user');
      if (res.data.success && res.data.user) {
        setUser(res.data.user);
        setToken(storedToken);
      } else {
        localStorage.removeItem('auth_token');
        setUser(null);
        setToken(null);
      }
    } catch (err) {
      console.error('Auth verification error:', err);
      localStorage.removeItem('auth_token');
      setUser(null);
      setToken(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  // Login handler
  const login = async (email, password) => {
    setError(null);
    try {
      const res = await axiosInstance.post('/login', { email, password });
      if (res.data.success && res.data.token) {
        const newToken = res.data.token;
        const userData = res.data.user;
        localStorage.setItem('auth_token', newToken);
        setToken(newToken);
        setUser(userData);
        return { success: true, user: userData };
      }
      throw new Error(res.data.message || 'Login failed');
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Invalid email or password';
      setError(msg);
      return { success: false, message: msg };
    }
  };

  // Register handler
  const register = async (name, email, password) => {
    setError(null);
    try {
      const res = await axiosInstance.post('/register', { name, email, password });
      if (res.data.success && res.data.token) {
        const newToken = res.data.token;
        const userData = res.data.user;
        localStorage.setItem('auth_token', newToken);
        setToken(newToken);
        setUser(userData);
        return { success: true, user: userData };
      }
      throw new Error(res.data.message || 'Registration failed');
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Registration failed';
      setError(msg);
      return { success: false, message: msg };
    }
  };

  // Logout handler
  const logout = async () => {
    try {
      await axiosInstance.post('/logout');
    } catch (err) {
      // Ignore logout errors and proceed to clear client state
    } finally {
      localStorage.removeItem('auth_token');
      setUser(null);
      setToken(null);
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        loading,
        error,
        isAuthenticated: !!user && !!token,
        login,
        register,
        logout,
        checkAuth,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}
