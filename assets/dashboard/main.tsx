// Authenticated dashboard SPA entry. Mounts under /app/* routes.
// Phase 1: stub. Phase 2 builds the invoice CRUD + metrics + payments table.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../styles/app.css';

function App() {
  return (
    <div className="min-h-dvh grid place-items-center text-center p-8">
      <div>
        <h1 className="font-display text-3xl font-semibold tracking-tight">Settle dashboard</h1>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
          React SPA boots here. See <code>assets/dashboard/</code>.
        </p>
      </div>
    </div>
  );
}

const root = document.getElementById('dashboard-root');
if (root) {
  createRoot(root).render(<StrictMode><App /></StrictMode>);
}
