// VITE_* variables are inlined at build time (build-scoped variables).
const api = import.meta.env.VITE_API_URL ?? '(VITE_API_URL not set)';

document.querySelector('#app').textContent = `Hello from launchroom (route: ${location.pathname}, api: ${api})`;
