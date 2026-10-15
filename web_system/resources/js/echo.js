import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const runtimeReverbConfig = window.__STITCH_RUNTIME__?.reverb ?? {};

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: runtimeReverbConfig.key ?? import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: runtimeReverbConfig.host ?? import.meta.env.VITE_REVERB_HOST,
    wsPort: runtimeReverbConfig.port ?? import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: runtimeReverbConfig.port ?? import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (runtimeReverbConfig.scheme ?? import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
