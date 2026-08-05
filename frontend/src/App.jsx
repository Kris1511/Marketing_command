import React, { useState } from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import Sidebar from './components/Sidebar';
import Topbar from './components/Topbar';
import DashboardPage from './pages/DashboardPage';
import LeadsPage from './pages/LeadsPage';
import ClientsPage from './pages/ClientsPage';
import PublishingPage from './pages/PublishingPage';
import IntegrationsPage from './pages/IntegrationsPage';
import ReportsPage from './pages/ReportsPage';
import './assets/styles.css';

export default function App() {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  return (
    <Router>
      <div className="app-shell">
        <Sidebar isOpen={mobileMenuOpen} onClose={() => setMobileMenuOpen(false)} />
        <div className="main-shell">
          <Topbar onToggleMobileMenu={() => setMobileMenuOpen(!mobileMenuOpen)} />
          <main className="content">
            <Routes>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/clients" element={<ClientsPage />} />
              <Route path="/publishing" element={<PublishingPage />} />
              <Route path="/leads" element={<LeadsPage />} />
              <Route path="/reports" element={<ReportsPage />} />
              <Route path="/notifications" element={<DashboardPage />} />
              <Route path="/integrations" element={<IntegrationsPage />} />
              <Route path="/team" element={<ClientsPage />} />
            </Routes>
          </main>
        </div>
      </div>
    </Router>
  );
}
