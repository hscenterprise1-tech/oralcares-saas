import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export function useKeyboardShortcuts() {
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            const activeElement = document.activeElement;
            const isInputFocus = activeElement?.tagName === 'INPUT' || activeElement?.tagName === 'TEXTAREA' || (activeElement as HTMLElement)?.isContentEditable;

            // Ctrl + K -> Global Search
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                document.dispatchEvent(new CustomEvent('open-global-search'));
            }

            // Ctrl + N -> Quick Create Prospect
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'n') {
                event.preventDefault();
                router.visit('/company/crm/leads/create');
            }

            // / -> Focus Search
            if (event.key === '/' && !isInputFocus) {
                event.preventDefault();
                const mainSearch = document.querySelector('input[type="search"]') as HTMLInputElement;
                if (mainSearch) {
                    mainSearch.focus();
                } else {
                    document.dispatchEvent(new CustomEvent('open-global-search'));
                }
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);
}
