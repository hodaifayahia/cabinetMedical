import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import LockLayout from '@/layouts/LockLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Drclick';
const cspNonceElement =
    typeof document === 'undefined'
        ? null
        : document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]');
const cspNonce =
    cspNonceElement?.nonce ||
    cspNonceElement?.getAttribute('nonce') ||
    undefined;

createInertiaApp({
    nonce: cspNonce,
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name === 'auth/LockScreen':
                return LockLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('uploads/'):
                return null;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#00666f',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
