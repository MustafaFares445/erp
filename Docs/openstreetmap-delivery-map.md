# OpenStreetMap Delivery Map — Implementation Guide

Reusable pattern for a delivery map with **multiple origin locations, one destination, and real road-drawn routes**, built with [Leaflet](https://leafletjs.com/) + [OpenStreetMap](https://www.openstreetmap.org/) + [OSRM](http://project-osrm.org/) — no API key, no vendor lock-in, no billing account required.

This is extracted from the working implementation in this repo:

- View: [delivery-customer-map.blade.php](../resources/views/filament/inventory-operations/delivery-customer-map.blade.php)
- Alpine component: [customer-delivery-map.js](../resources/js/filament/customer-delivery-map.js)
- Styles: [customer-delivery-map.css](../resources/css/filament/customer-delivery-map.css)
- Backend route fetcher (server-side variant): [RoadRouteFetcher.php](../app/Services/Orders/RoadRouteFetcher.php)
- Address picker with geocoding: [CustomerLocationPicker.php](../app/Filament/Forms/Components/CustomerLocationPicker.php) + [customer-location-picker.js](../resources/js/filament/customer-location-picker.js)

It is framework-agnostic in principle (plain Leaflet + fetch), but the wiring shown for registering assets is Filament/Livewire-specific. Swap that layer out for plain Blade/HTML, React, or Vue as needed.

## What it looks like

- One fixed **destination marker** (e.g. customer delivery address).
- One or more **origin markers** (e.g. warehouses), added/removed dynamically as the user selects them.
- A **colored polyline per origin** that follows actual roads (not a straight line), fetched from OSRM.
- The map **auto-fits its bounds** to show every marker whenever the set of origins changes.
- If OSRM is unreachable or returns no route, it **falls back to a dashed straight line** so the UI never breaks.

## 1. Dependencies

```bash
npm install leaflet
```

Leaflet needs its CSS loaded globally once (e.g. in your main JS entrypoint or a `<link>` tag):

```js
import 'leaflet/dist/leaflet.css';
```

No JS framework is required — Leaflet is imported globally as `window.L` in this implementation (see asset registration below), and the map logic is a plain object of methods (works as an Alpine.js component, a Vue `setup()` return, or a plain class).

## 2. Services used (both free, no API key)

| Purpose | Service | Endpoint pattern |
|---|---|---|
| Map tiles | OpenStreetMap tile server | `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png` |
| Road-following route geometry | OSRM demo server (or self-hosted) | `https://router.project-osrm.org/route/v1/driving/{lon1},{lat1};{lon2},{lat2}?overview=full&geometries=geojson` |
| Address search (geocoding) | Nominatim | `https://nominatim.openstreetmap.org/search?format=json&limit=1&q={query}` |

**Important for production**: the public OSRM/Nominatim demo servers are rate-limited and not meant for sustained production traffic. Self-host OSRM (Docker image `osrm/osrm-backend`) with your own extracted region, and set its URL via config/env — never hardcode it. In this repo that's `config('services.osrm.url')`, backed by `OSRM_URL` in `.env` (defaults to the public demo server for local dev).

## 3. Backend: expose config + view data

```php
// config/services.php
'osrm' => [
    'url' => env('OSRM_URL', 'https://router.project-osrm.org'),
],
```

Pass the destination, the list of possible origins, and the routing base URL into the view:

```php
$viewData = [
    'destinationName' => $customer->company_name,
    'latitude' => (float) $customer->latitude,
    'longitude' => (float) $customer->longitude,
    // every origin the user could pick from (id/name/coords)
    'originOptions' => $warehouses->map(fn ($w) => [
        'id' => $w->id, 'name' => $w->name,
        'latitude' => (float) $w->latitude, 'longitude' => (float) $w->longitude,
    ])->all(),
    // origins currently selected/active (drives what's drawn on first paint)
    'selectedOrigins' => $selectedWarehouses,
    'routingServiceUrl' => config('services.osrm.url'),
];
```

Never trust the routing URL from the frontend — always resolve it server-side from config and pass it down, so an attacker can't redirect route requests to an arbitrary host (see §6, SSRF note).

## 4. The Blade/HTML shell

```blade
<div
    x-data="deliveryMap({
        destinationName: @js($destinationName),
        latitude: @js($latitude),
        longitude: @js($longitude),
        origins: @js($selectedOrigins),
        originOptions: @js($originOptions),
        routingServiceUrl: @js($routingServiceUrl),
    })"
>
    <div x-ref="map" wire:ignore class="delivery-map"></div>
</div>
```

- `wire:ignore` (Livewire) or an equivalent "don't let the framework touch this DOM" directive is essential — Leaflet owns this `<div>`'s internals directly and a framework re-render would destroy the map instance.
- If reactive inputs change which origins are selected (e.g. a repeater field), watch that state and re-render — don't rebuild the whole map, just the markers/routes (see `renderWarehouseRoutes` below).

## 5. The map component (Alpine.js; portable pattern)

Full reference implementation — copy and rename `warehouse(s)` → your "origin" concept:

```js
const RouteColors = ['#f59e0b', '#2563eb', '#16a34a', '#dc2626'];

export default function deliveryMap({ destinationName, latitude, longitude, origins, originOptions, routingServiceUrl }) {
    return {
        map: null,
        originLayerGroup: null,
        routeLayerGroup: null,
        originLookup: new Map(),
        resizeObserver: null,
        routeAbortController: null,
        routeRevision: 0,       // guards against out-of-order async responses
        destination: null,

        init() {
            this.destination = this.toLatLng(latitude, longitude);
            if (!this.destination) return;

            this.createMap(destinationName);
            this.originLookup = new Map(
                this.withCoords(originOptions).map(o => [o.id, o])
            );

            this.renderRoutes(this.withCoords(origins));
        },

        createMap(destinationName) {
            this.map = window.L.map(this.$refs.map, { scrollWheelZoom: false })
                .setView(this.destination, 16);

            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
            }).addTo(this.map);

            window.L.marker(this.destination, { title: destinationName }).addTo(this.map)
                .bindPopup(destinationName ?? 'Destination');

            this.originLayerGroup = window.L.layerGroup().addTo(this.map);
            this.routeLayerGroup = window.L.layerGroup().addTo(this.map);

            // Leaflet needs an explicit resize kick if the container's size
            // isn't stable at mount time (e.g. inside a wizard step, modal, or tab)
            this.resizeObserver = new ResizeObserver(() => this.map?.invalidateSize());
            this.resizeObserver.observe(this.$refs.map);
            requestAnimationFrame(() => this.map?.invalidateSize());
        },

        toLatLng(lat, lng) {
            const a = Number.parseFloat(lat), b = Number.parseFloat(lng);
            return Number.isFinite(a) && Number.isFinite(b) ? [a, b] : null;
        },

        withCoords(list) {
            return (Array.isArray(list) ? list : [])
                .map(o => {
                    const coords = this.toLatLng(o?.latitude, o?.longitude);
                    return coords ? { id: o.id, name: o.name ?? 'Origin', coordinates: coords } : null;
                })
                .filter(Boolean);
        },

        async renderRoutes(origins) {
            const revision = ++this.routeRevision;   // invalidate any in-flight fetches
            this.routeAbortController?.abort();
            this.originLayerGroup.clearLayers();
            this.routeLayerGroup.clearLayers();

            origins.forEach(o => {
                window.L.marker(o.coordinates, { title: o.name }).addTo(this.originLayerGroup);
            });
            this.fitToMarkers(origins);

            if (origins.length === 0) return;

            this.routeAbortController = new AbortController();
            await Promise.all(origins.map((o, i) => this.drawRoute(o, i, revision)));
        },

        fitToMarkers(origins) {
            if (origins.length === 0) { this.map.setView(this.destination, 16); return; }
            const bounds = window.L.latLngBounds([this.destination]);
            origins.forEach(o => bounds.extend(o.coordinates));
            this.map.invalidateSize({ pan: false });
            this.map.fitBounds(bounds, { maxZoom: 16, padding: [40, 40] });
        },

        async drawRoute(origin, index, revision) {
            const color = RouteColors[index % RouteColors.length];
            const geometry = await this.roadGeometry(origin);
            if (revision !== this.routeRevision) return;   // a newer render superseded this one

            if (geometry) {
                window.L.geoJSON(geometry, { style: { color, weight: 5 } }).addTo(this.routeLayerGroup);
                return;
            }
            // network/route failure: still show something
            window.L.polyline([origin.coordinates, this.destination], {
                color, dashArray: '8 8', opacity: 0.8, weight: 4,
            }).addTo(this.routeLayerGroup);
        },

        async roadGeometry(origin) {
            try {
                const res = await fetch(this.routeEndpoint(origin), {
                    headers: { Accept: 'application/json' },
                    signal: this.routeAbortController.signal,
                });
                if (!res.ok) return null;
                const payload = await res.json();
                const geometry = payload?.routes?.[0]?.geometry;
                return geometry?.type === 'LineString' ? geometry : null;
            } catch {
                return null;   // aborted, offline, CORS, malformed JSON — all treated as "no route"
            }
        },

        routeEndpoint(origin) {
            const base = new URL(routingServiceUrl);
            if (!['http:', 'https:'].includes(base.protocol)) {
                throw new TypeError('The routing service URL must use HTTP or HTTPS.');
            }
            const [oLat, oLng] = origin.coordinates;
            const [dLat, dLng] = this.destination;
            const coords = `${oLng},${oLat};${dLng},${dLat}`;   // OSRM wants lon,lat order
            const url = new URL(`route/v1/driving/${coords}`, `${base.toString().replace(/\/$/, '')}/`);
            url.searchParams.set('overview', 'full');
            url.searchParams.set('geometries', 'geojson');
            return url;
        },

        destroy() {
            this.routeRevision++;
            this.routeAbortController?.abort();
            this.resizeObserver?.disconnect();
            this.map?.remove();
            this.map = null;
        },
    };
}
```

### Key mechanics worth keeping when you port this

1. **Coordinate order flips.** Leaflet/`[lat, lng]` vs GeoJSON/OSRM `[lng, lat]`. Every bug in this kind of code is a swapped pair — centralize the conversion in one or two helper functions (`toLatLng`, `routeEndpoint`) instead of inlining it everywhere.
2. **`routeRevision` counter.** When origins change quickly (e.g. user is still clicking checkboxes), older `fetch` calls can resolve after newer ones. Stamp each render pass with an incrementing number and drop results whose stamp is stale.
3. **`AbortController` per render pass.** Cancel in-flight requests when origins change again, so you don't pay for/render routes nobody asked for anymore.
4. **Fallback straight line.** OSRM can time out, rate-limit, or simply have no coverage for a region. Never let a routing failure blank out the map — draw a dashed line as a degraded-but-honest signal.
5. **`ResizeObserver` + `invalidateSize()`.** Leaflet computes its canvas size at construction time. If the container is inside a tab, modal, wizard step, or anything that starts at `display: none` or animates its size, the map renders broken/blank until you call `invalidateSize()` after it becomes visible.
6. **`wire:ignore` / no-diff zone.** Any reactive framework (Livewire, React, Vue) must be told not to manage this DOM node — Leaflet's own DOM mutations will otherwise fight the framework's diffing.
7. **Layer groups, not ad-hoc arrays.** Keep origin markers and routes in separate `L.layerGroup()`s so a re-render is a cheap `clearLayers()` + re-add, not a full map teardown/rebuild.

## 6. Security notes

- **Never let the frontend supply the OSRM base URL.** Resolve it from server config only (`config('services.osrm.url')`), or a malicious actor could point `routingServiceUrl` at an internal-network host and use your users' browsers to probe it (SSRF via the client, though less severe than server-side SSRF since it runs in the browser's network context — still validate protocol as shown in `routeEndpoint`).
- If you ever fetch routes **server-side** instead of from the browser (e.g. to cache them, or to avoid CORS), apply the same URL validation server-side and add a short timeout — see [RoadRouteFetcher.php](../app/Services/Orders/RoadRouteFetcher.php) for a minimal example (3s timeout, `Http::timeout()`, catches all `Throwable`, treats any non-2xx or malformed payload as "no route" rather than surfacing an error).
- Nominatim (geocoding) usage is subject to their [usage policy](https://operations.osmfoundation.org/policies/nominatim/) — no heavy/automated querying, must set a descriptive `User-Agent` in production, and self-hosting is recommended above light hobby traffic.

## 7. Optional companion: address picker with geocoding + draggable pin

For letting a user *set* a location (as opposed to just displaying one), see [customer-location-picker.js](../resources/js/filament/customer-location-picker.js):

- Click-to-place and drag-to-adjust marker.
- A search box that geocodes free-text via Nominatim and recenters the map.
- Two-way binding of `latitude`/`longitude` back to the host form (Filament's `$wire.$entangle`, but the same idea maps to a `v-model` pair or two React state setters).

This is a separate, smaller component — pair it with the delivery map above when building an "select/confirm a delivery address" + "preview routes to it" flow, as this repo's delivery wizard does.

## 8. Porting checklist for a new project

- [ ] Install `leaflet`, import its CSS once globally.
- [ ] Load Leaflet's JS as `window.L` (or adapt the snippets above to `import L from 'leaflet'` and drop the `window.` prefix).
- [ ] Add an `OSRM_URL` env var (self-hosted in production; public demo acceptable for local dev only).
- [ ] Resolve `routingServiceUrl` server-side, never from client input.
- [ ] Build the view-data payload: destination `{lat, lng, name}` + list of origin candidates `{id, name, lat, lng}`.
- [ ] Copy the component above, rename `warehouse`/`origin` terms to your domain.
- [ ] Wire framework "don't touch this DOM" directive on the map container.
- [ ] Verify: resize inside a hidden container (tab/modal/wizard step) still renders correctly after becoming visible.
- [ ] Verify: killing network mid-route-fetch falls back to the dashed line instead of an error state.
