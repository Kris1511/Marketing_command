import React, { useState } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { WorkspaceProvider } from './context/WorkspaceContext';
import ProtectedRoute from './components/ProtectedRoute';
import Sidebar from './components/Sidebar';
import Topbar from './components/Topbar';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import LeadsPage from './pages/LeadsPage';
import ClientsPage from './pages/ClientsPage';
import PublishingPage from './pages/PublishingPage';
import IntegrationsPage from './pages/IntegrationsPage';
import ReportsPage from './pages/ReportsPage';
import NotificationsPage from './pages/NotificationsPage';
import InboxPage from './pages/InboxPage';
import CommentsPage from './pages/CommentsPage';
import TeamPage from './pages/TeamPage';
import './assets/styles.css';

function MainLayout() {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const location = useLocation();

  const getPageInfo = (pathname) => {
    switch (pathname) {
      case '/publishing':
        return { title: 'Publishing', subtitle: 'Create, schedule and publish content across channels' };
      case '/clients':
        return { title: 'Clients', subtitle: 'Manage client accounts and workspaces' };
      case '/leads':
        return { title: 'CRM & Leads', subtitle: 'Track and manage your leads and conversions' };
      case '/reports':
        return { title: 'Reports & Analytics', subtitle: 'Campaign and channel performance metrics' };
      case '/inbox':
        return { title: 'Inbox', subtitle: 'Manage conversations and messages' };
      case '/comments':
        return { title: 'Comments', subtitle: 'Monitor and reply to comments across channels' };
      case '/notifications':
        return { title: 'Notifications', subtitle: 'System updates and channel alerts' };
      case '/integrations':
        return { title: 'API Connections', subtitle: 'Manage third-party integrations and accounts' };
      case '/team':
        return { title: 'Team & Access', subtitle: 'Manage users, permissions and team members' };
      default:
        return { title: 'Overview', subtitle: 'All important updates in one place' };
    }
  };

  const pageInfo = getPageInfo(location.pathname);

  return (
    <div className="app-shell">
      <Sidebar isOpen={mobileMenuOpen} onClose={() => setMobileMenuOpen(false)} />
      <div className="main-shell">
        <Topbar
          onToggleMobileMenu={() => setMobileMenuOpen(!mobileMenuOpen)}
          title={pageInfo.title}
          subtitle={pageInfo.subtitle}
        />
        <main className="content">
          <Routes>
            <Route path="/" element={<ProtectedRoute><DashboardPage /></ProtectedRoute>} />
            <Route path="/clients" element={<ProtectedRoute><ClientsPage /></ProtectedRoute>} />
            <Route path="/publishing" element={<ProtectedRoute><PublishingPage /></ProtectedRoute>} />
            <Route path="/leads" element={<ProtectedRoute><LeadsPage /></ProtectedRoute>} />
            <Route path="/reports" element={<ProtectedRoute><ReportsPage /></ProtectedRoute>} />
            <Route path="/inbox" element={<ProtectedRoute><InboxPage /></ProtectedRoute>} />
            <Route path="/comments" element={<ProtectedRoute><CommentsPage /></ProtectedRoute>} />
            <Route path="/notifications" element={<ProtectedRoute><NotificationsPage /></ProtectedRoute>} />
            <Route path="/integrations" element={<ProtectedRoute><IntegrationsPage /></ProtectedRoute>} />
            <Route path="/team" element={<ProtectedRoute><TeamPage /></ProtectedRoute>} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </div>
    </div>
  );
}

export default function App() {
  return (
    <Router>
      <AuthProvider>
        <WorkspaceProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/*" element={<MainLayout />} />
          </Routes>
        </WorkspaceProvider>
      </AuthProvider>
    </Router>
  );
}
