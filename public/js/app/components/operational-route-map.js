import loadLeaflet from './leaflet-loader.js';

await loadLeaflet();

/** Road geometry is rendered only when returned by a router; no fabricated ETA. */
export default function operationalRouteMap({ source, destination, routingServiceUrl }) {
    return {
        map: null,
        resizeObserver: null,
        controller: null,
        state: 'loading',
        details: '',
        directionsUrl: null,

        point(value) {
            const lat = Number(value?.latitude);
            const lon = Number(value?.longitude);
            return Number.isFinite(lat) && Number.isFinite(lon)
                && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 ? [lat, lon] : null;
        },

        async init() {
            const start = this.point(source);
            const end = this.point(destination);
            const valid = [start, end].filter(Boolean);
            if (!valid.length) { this.state = 'missing'; return; }

            this.map = window.L.map(this.$refs.map, { scrollWheelZoom: false });
            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
            }).addTo(this.map);

            if (start) window.L.marker(start).addTo(this.map)
                .bindPopup(document.createTextNode(source.label || 'Origin'));
            if (end) window.L.marker(end).addTo(this.map)
                .bindPopup(document.createTextNode(destination.label || 'Destination'));

            const bounds = window.L.latLngBounds(valid);
            this.resizeObserver = new ResizeObserver(() => this.map?.invalidateSize());
            this.resizeObserver.observe(this.$refs.map);
            requestAnimationFrame(() => {
                this.map?.invalidateSize();
                if (valid.length === 1) this.map?.setView(valid[0], 13);
                else this.map?.fitBounds(bounds, { padding: [32, 32], maxZoom: 15 });
            });

            if (!start || !end) { this.state = 'partial'; return; }
            const directions = new URL('https://www.openstreetmap.org/directions');
            directions.searchParams.set('engine', 'fossgis_osrm_car');
            directions.searchParams.set('route', start.join(',') + ';' + end.join(','));
            this.directionsUrl = directions.toString();

            if (!routingServiceUrl) { this.state = 'unavailable'; return; }
            try {
                const base = new URL(routingServiceUrl);
                if (!['https:', 'http:'].includes(base.protocol)) {
                    this.state = 'unavailable'; return;
                }
                const coords = start[1] + ',' + start[0] + ';' + end[1] + ',' + end[0];
                const url = new URL('route/v1/driving/' + coords, base.toString().replace(/\/$/, '') + '/');
                url.searchParams.set('overview', 'full');
                url.searchParams.set('geometries', 'geojson');
                this.controller = new AbortController();
                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' }, signal: this.controller.signal,
                });
                if (!response.ok) throw new Error('Router unavailable');
                const payload = await response.json();
                const route = payload?.routes?.[0];
                if (!route || route.geometry?.type !== 'LineString'
                    || !Array.isArray(route.geometry.coordinates)) throw new Error('No geometry');
                window.L.geoJSON(route.geometry, { style: { color: '#2563eb', weight: 5 } })
                    .addTo(this.map);
                this.state = 'ready';
                if (Number.isFinite(route.distance) && Number.isFinite(route.duration)) {
                    this.details = (route.distance / 1000).toFixed(1) + ' km · '
                        + Math.round(route.duration / 60) + ' min (estimated)';
                }
            } catch (error) {
                if (error?.name !== 'AbortError') this.state = 'unavailable';
            }
        },
        destroy() {
            this.controller?.abort();
            this.resizeObserver?.disconnect();
            this.map?.remove();
            this.map = null;
        },
    };
}
