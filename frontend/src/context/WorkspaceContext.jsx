import React, { createContext, useContext, useState, useEffect } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useAuth } from '../hooks/useAuth';

const WorkspaceContext = createContext(null);

export function WorkspaceProvider({ children }) {
  const { user } = useAuth();
  const [workspaces, setWorkspaces] = useState([]);
  const [selectedWorkspaceId, setSelectedWorkspaceIdState] = useState(() => {
    return localStorage.getItem('selectedWorkspaceId') || localStorage.getItem('activeWorkspaceId') || '';
  });
  const [loadingWorkspaces, setLoadingWorkspaces] = useState(true);

  const fetchWorkspaces = async () => {
    if (!user) {
      setLoadingWorkspaces(false);
      return;
    }
    try {
      const res = await axiosInstance.get('/workspaces');
      if (res.data.success && res.data.data) {
        const list = res.data.data;
        setWorkspaces(list);

        // If no workspace is selected or selected workspace isn't valid, select best one
        if (list.length > 0) {
          const storedId = localStorage.getItem('selectedWorkspaceId') || localStorage.getItem('activeWorkspaceId');
          const isValidStored = storedId && (storedId === 'all' || list.some((w) => String(w.id) === String(storedId)));
          if (!isValidStored) {
            const bestWs = list.find((w) => (w.channels_count || 0) > 0) || list[0];
            const chosenId = String(bestWs.id);
            setSelectedWorkspaceIdState(chosenId);
            localStorage.setItem('selectedWorkspaceId', chosenId);
            localStorage.setItem('activeWorkspaceId', chosenId);
          }
        }
      }
    } catch (err) {
      console.error('Error fetching workspaces in WorkspaceContext:', err);
    } finally {
      setLoadingWorkspaces(false);
    }
  };

  useEffect(() => {
    fetchWorkspaces();
  }, [user]);

  const setSelectedWorkspaceId = (id) => {
    const stringId = String(id);
    setSelectedWorkspaceIdState(stringId);
    localStorage.setItem('selectedWorkspaceId', stringId);
    localStorage.setItem('activeWorkspaceId', stringId);
  };

  const selectedWorkspace =
    workspaces.find((w) => String(w.id) === String(selectedWorkspaceId)) || null;

  return (
    <WorkspaceContext.Provider
      value={{
        workspaces,
        selectedWorkspaceId,
        activeWorkspaceId: selectedWorkspaceId,
        selectedWorkspace,
        activeWorkspace: selectedWorkspace,
        setSelectedWorkspaceId,
        setActiveWorkspaceId: setSelectedWorkspaceId,
        refreshWorkspaces: fetchWorkspaces,
        fetchWorkspaces,
        loadingWorkspaces,
        loading: loadingWorkspaces,
      }}
    >
      {children}
    </WorkspaceContext.Provider>
  );
}

export function useWorkspace() {
  const context = useContext(WorkspaceContext);
  if (!context) {
    throw new Error('useWorkspace must be used within a WorkspaceProvider');
  }
  return context;
}
