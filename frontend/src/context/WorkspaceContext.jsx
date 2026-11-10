import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
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
  const [workspacesError, setWorkspacesError] = useState('');

  // Resolves to true when the list was refreshed, false when it could not be loaded.
  const fetchWorkspaces = async () => {
    if (!user) {
      setLoadingWorkspaces(false);
      return false;
    }
    try {
      const res = await axiosInstance.get('/workspaces');
      setWorkspacesError('');
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
      return true;
    } catch (err) {
      console.error('Error fetching workspaces in WorkspaceContext:', err);
      setWorkspacesError(err.response?.data?.message || 'Unable to load client workspaces. Please try again.');
      return false;
    } finally {
      setLoadingWorkspaces(false);
    }
  };

  useEffect(() => {
    fetchWorkspaces();
  }, [user]);

  // A page (e.g. Publishing) can register a guard that may veto a client switch, for example while there are
  // unsaved changes. The guard receives the requested id and returns false to keep the current workspace.
  const switchGuardRef = useRef(null);
  const registerWorkspaceSwitchGuard = useCallback((guard) => {
    switchGuardRef.current = guard;
    return () => {
      if (switchGuardRef.current === guard) switchGuardRef.current = null;
    };
  }, []);

  // Returns true when the switch happened, false when a guard cancelled it.
  const setSelectedWorkspaceId = (id) => {
    const stringId = String(id);
    if (stringId !== String(selectedWorkspaceId) && switchGuardRef.current && switchGuardRef.current(stringId) === false) {
      return false;
    }
    setSelectedWorkspaceIdState(stringId);
    localStorage.setItem('selectedWorkspaceId', stringId);
    localStorage.setItem('activeWorkspaceId', stringId);
    return true;
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
        registerWorkspaceSwitchGuard,
        refreshWorkspaces: fetchWorkspaces,
        fetchWorkspaces,
        loadingWorkspaces,
        workspacesError,
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
