import { useEffect, useRef, useState } from 'react';
import {
  parseWorkspaceToRuntimeMessage,
  SDM_PROTOCOL_VERSION,
  type RuntimeToWorkspaceMessage,
} from './core/protocol';
import type { SlideDocument } from './core/schema';

const RUNTIME_CAPABILITIES: Array<string> = [];

const TRUSTED_WORKSPACE_ORIGINS = new Set([
  'https://replit.com',
  'https://replit-staging.com',
  'https://staging.replit.com',
]);

interface SessionTarget {
  targetWindow: Window;
  origin: string;
  sessionId: string;
}

function isTrustedWorkspaceOrigin(origin: string): boolean {
  if (TRUSTED_WORKSPACE_ORIGINS.has(origin)) {
    return true;
  }

  try {
    const { hostname, protocol } = new URL(origin);

    return (
      (protocol === 'http:' &&
        (hostname === 'localhost' || hostname === '127.0.0.1')) ||
      (protocol === 'https:' &&
        hostname.startsWith('web--') &&
        hostname.endsWith('.z.zergrush.dev'))
    );
  } catch {
    return false;
  }
}

function findAncestorWindow(source: MessageEventSource | null): Window | null {
  if (source === null) {
    return null;
  }
  let ancestor: Window = window;
  while (ancestor.parent !== ancestor) {
    ancestor = ancestor.parent;
    if (source === ancestor) {
      return ancestor;
    }
  }

  return null;
}

function post(session: SessionTarget, message: RuntimeToWorkspaceMessage) {
  session.targetWindow.postMessage(message, session.origin);
}

export function useSdmRuntimeSession({
  slideId,
  document,
}: {
  slideId: string;
  document: SlideDocument | null;
}): { editing: boolean } {
  const [editing, setEditing] = useState(false);
  const documentRef = useRef(document);
  documentRef.current = document;
  const sessionRef = useRef<SessionTarget | null>(null);

  useEffect(() => {
    if (window.parent === window) {
      return;
    }
    window.parent.postMessage(
      {
        type: 'sdm:ready',
        supportedProtocolVersions: [SDM_PROTOCOL_VERSION],
        slideId,
        capabilities: RUNTIME_CAPABILITIES,
      } satisfies RuntimeToWorkspaceMessage,
      '*',
    );
  }, [slideId]);

  useEffect(() => {
    const handleMessage = (event: MessageEvent) => {
      const result = parseWorkspaceToRuntimeMessage(event.data);
      if (!result.ok) {
        return;
      }
      const message = result.message;
      if (message.slideId !== slideId || message.type !== 'sdm:setEditMode') {
        return;
      }
      if (message.enabled) {
        const ancestor = findAncestorWindow(event.source);
        const currentDocument = documentRef.current;
        if (
          ancestor === null ||
          currentDocument === null ||
          !isTrustedWorkspaceOrigin(event.origin)
        ) {
          return;
        }
        const session: SessionTarget = {
          targetWindow: ancestor,
          origin: event.origin,
          sessionId: message.sessionId,
        };
        sessionRef.current = session;
        setEditing(true);
        post(session, {
          type: 'sdm:ready',
          supportedProtocolVersions: [SDM_PROTOCOL_VERSION],
          slideId,
          sessionId: session.sessionId,
          capabilities: RUNTIME_CAPABILITIES,
        });
        post(session, {
          type: 'sdm:doc',
          protocolVersion: SDM_PROTOCOL_VERSION,
          slideId,
          sessionId: session.sessionId,
          document: currentDocument,
          selectedIds: [],
        });

        return;
      }
      const session = sessionRef.current;
      if (
        session === null ||
        session.sessionId !== message.sessionId ||
        event.source !== session.targetWindow
      ) {
        return;
      }
      sessionRef.current = null;
      setEditing(false);
    };
    window.addEventListener('message', handleMessage);

    return () => window.removeEventListener('message', handleMessage);
  }, [slideId]);

  return { editing };
}
