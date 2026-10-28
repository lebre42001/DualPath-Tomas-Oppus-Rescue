import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const runtimeReverbConfig = window.__STITCH_RUNTIME__?.reverb ?? {};

// A missing/invalid realtime configuration must not disable the rest of the app
// or the authenticated incoming-report polling fallback.
const reverbKey = runtimeReverbConfig.key ?? import.meta.env.VITE_REVERB_APP_KEY;
window.Echo = null;
if (reverbKey) {
    try {
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: reverbKey,
            wsHost: runtimeReverbConfig.host ?? import.meta.env.VITE_REVERB_HOST,
            wsPort: runtimeReverbConfig.port ?? import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: runtimeReverbConfig.port ?? import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (runtimeReverbConfig.scheme ?? import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    } catch (error) {
        console.warn('Realtime connection unavailable; report polling remains active.', error);
    }
}
