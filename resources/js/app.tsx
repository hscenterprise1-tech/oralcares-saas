import { StrictMode } from 'react';
import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { GlobalProvider } from './contexts/GlobalProvider';

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'OralCares';

const pages = import.meta.glob<any>('./pages/**/*.tsx');

/*
 * The Clinic Portal's old frontend was removed for a rebuild. The server still
 * answers every Clinic route (same permissions, same data); a Clinic page with
 * no file yet renders the one placeholder instead of failing. Other portals
 * resolve exactly as before.
 */
const CLINIC_PAGE = /^Clinic(Portal)?\//;

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const path = `./pages/${name}.tsx`;

        return resolvePageComponent(!(path in pages) && CLINIC_PAGE.test(name) ? './pages/ClinicPortal/Unavailable.tsx' : path, pages);
    },
    setup({ el, App, props }) {
        const root = createRoot(el!);
        root.render(
            <StrictMode>
                <GlobalProvider>
                    <App {...props} />
                </GlobalProvider>
            </StrictMode>
        );
    },
    progress: {
        color: '#2563eb', // Blue-600
    },
});
