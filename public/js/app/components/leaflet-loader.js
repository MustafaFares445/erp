const leafletJsUrl = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
const leafletCssUrl = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';

let scriptPromise = null;

function ensureStylesheet() {
    if (document.head.querySelector('link[data-leaflet-css]')) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = leafletCssUrl;
        link.dataset.leafletCss = '';
        // A missing stylesheet must not stop the map from initialising.
        link.onload = resolve;
        link.onerror = resolve;
        document.head.appendChild(link);
    });
}

function ensureScript() {
    if (window.L) {
        return Promise.resolve();
    }

    scriptPromise ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = leafletJsUrl;
        script.onload = resolve;
        script.onerror = () => {
            scriptPromise = null;
            reject(new Error('Leaflet failed to load.'));
        };
        document.head.appendChild(script);
    });

    return scriptPromise;
}

/**
 * Loads Leaflet on demand, so only pages that actually render a map download it.
 *
 * The script is fetched once per page session (`window.L` survives SPA navigation), while the
 * stylesheet is re-checked on every call because Livewire's head merge on `wire:navigate` can
 * drop a stylesheet that was added dynamically.
 */
export default async function loadLeaflet() {
    await Promise.all([ensureStylesheet(), ensureScript()]);

    return window.L;
}
