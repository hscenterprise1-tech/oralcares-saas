import { StrictMode } from 'react';
import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { GlobalProvider } from './contexts/GlobalProvider';

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'OralCares';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob<any>('./pages/**/*.tsx')),
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
