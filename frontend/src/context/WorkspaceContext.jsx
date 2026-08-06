import React, { createContext, useContext, useState, useEffect } from 'react';
import axios from 'axios';

const WorkspaceContext = createContext();

export function WorkspaceProvider({ children }) {
  const [workspaces, setWorkspaces] = useState([]);
  const [activeWorkspaceId, setActiveWorkspaceId] = useState(
    localStorage.getItem('activeWorkspaceId') || ''
  );
  const [loading, setLoading] = useState(true);

  const fetchWorkspaces = async () => {
    setLoading(true);
    try {
      const res = await axios.get('/api/v1/workspaces');
      if (res.data.success) {
        const data = res.data.data;
        setWorkspaces(data);
        if (data.length > 0) {
          // If no active workspace is set or current set ID doesn't exist in data, set to first
          const exists = data.some((w) => String(w.id) === String(activeWorkspaceId));
          if (!activeWorkspaceId || !exists) {
            setActiveWorkspaceId(String(data[0].id));
            localStorage.setItem('activeWorkspaceId', String(data[0].id));
          }
        }
      }
    } catch (err) {
      console.error('Error fetching workspaces in context:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchWorkspaces();
  }, []);

  const handleSetActiveWorkspaceId = (id) => {
    setActiveWorkspaceId(String(id));
    localStorage.setItem('activeWorkspaceId', String(id));
  };

  const activeWorkspace = workspaces.find(
    (w) => String(w.id) === String(activeWorkspaceId)
  ) || workspaces[0] || null;

  return (
    <WorkspaceContext.Provider
      value={{
        workspaces,
        activeWorkspaceId,
        activeWorkspace,
        setActiveWorkspaceId: handleSetActiveWorkspaceId,
        fetchWorkspaces,
        loading,
      }}
    >
      {children}
    </WorkspaceContext.Provider>
  );
}

export function useWorkspace() {
  return useContext(WorkspaceContext);
}
