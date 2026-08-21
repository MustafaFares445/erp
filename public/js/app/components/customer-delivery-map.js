const RouteColors = ['#f59e0b', '#2563eb', '#16a34a', '#dc2626'];

export default function customerDeliveryMap({ customerName, latitude, longitude, warehouses, warehouseOptions, routingServiceUrl }) {
    return {
        map: null,
        warehouseLayerGroup: null,
        routeLayerGroup: null,
        warehouseLookup: new Map(),
        resizeObserver: null,
        routeAbortController: null,
        routeRevision: 0,
        customerLocation: null,

        async init() {
            this.customerLocation = this.location(latitude, longitude);

            if (! this.customerLocation) {
                return;
            }

            this.createMap(customerName);
            this.warehouseLookup = new Map(
                this.warehousesWithLocations(warehouseOptions).map((warehouse) => [warehouse.id, warehouse]),
            );
            this.$wire?.$watch('data.shipments', (shipments) => {
                this.renderWarehouseRoutes(this.selectedWarehouses(shipments));
            });

            const currentShipments = this.$wire?.$get('data.shipments');
            const selectedWarehouses = currentShipments === undefined
                ? this.warehousesWithLocations(warehouses)
                : this.selectedWarehouses(currentShipments);

            await this.renderWarehouseRoutes(selectedWarehouses);
        },

        createMap(customerName) {
            this.map = window.L.map(this.$refs.map, {
                scrollWheelZoom: false,
            }).setView(this.customerLocation, 16);

            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
            }).addTo(this.map);

            this.bindMarkerLabel(
                window.L.marker(this.customerLocation, { title: customerName ?? 'Customer' }).addTo(this.map),
                customerName ?? 'Customer delivery location',
            );

            this.warehouseLayerGroup = window.L.layerGroup().addTo(this.map);
            this.routeLayerGroup = window.L.layerGroup().addTo(this.map);
            this.resizeObserver = new ResizeObserver(() => this.map?.invalidateSize());
            this.resizeObserver.observe(this.$refs.map);

            requestAnimationFrame(() => this.map?.invalidateSize());
        },

        location(latitude, longitude) {
            const parsedLatitude = Number.parseFloat(latitude);
            const parsedLongitude = Number.parseFloat(longitude);

            if (! Number.isFinite(parsedLatitude) || ! Number.isFinite(parsedLongitude)) {
                return null;
            }

            return [parsedLatitude, parsedLongitude];
        },

        warehousesWithLocations(warehouses) {
            if (! Array.isArray(warehouses)) {
                return [];
            }

            return warehouses.map((warehouse) => this.warehouseWithLocation(warehouse)).filter(Boolean);
        },

        warehouseWithLocation(warehouse) {
            if (! warehouse || typeof warehouse !== 'object') {
                return null;
            }

            const id = this.warehouseId(warehouse.id);
            const coordinates = this.location(warehouse.latitude, warehouse.longitude);

            if (id === null || ! coordinates) {
                return null;
            }

            const name = typeof warehouse.name === 'string' && warehouse.name.trim()
                ? warehouse.name
                : 'Warehouse';

            return { id, name, coordinates };
        },

        warehouseId(value) {
            if (Number.isInteger(value)) {
                return value;
            }

            return typeof value === 'string' && /^\d+$/.test(value)
                ? Number.parseInt(value, 10)
                : null;
        },

        selectedWarehouses(shipments) {
            const warehouseIds = this.shipmentRows(shipments)
                .map((shipment) => this.warehouseId(shipment?.warehouse_id))
                .filter((warehouseId) => warehouseId !== null);

            return [...new Set(warehouseIds)]
                .map((warehouseId) => this.warehouseLookup.get(warehouseId))
                .filter(Boolean);
        },

        shipmentRows(shipments) {
            if (Array.isArray(shipments)) {
                return shipments;
            }

            return shipments && typeof shipments === 'object' ? Object.values(shipments) : [];
        },

        async renderWarehouseRoutes(warehouses) {
            const routeRevision = ++this.routeRevision;

            this.routeAbortController?.abort();
            this.warehouseLayerGroup.clearLayers();
            this.routeLayerGroup.clearLayers();
            this.addWarehouseMarkers(warehouses);
            this.fitMap(warehouses);

            if (warehouses.length === 0) {
                return;
            }

            this.routeAbortController = new AbortController();
            await Promise.all(warehouses.map((warehouse, index) => this.drawRoute(warehouse, index, routeRevision)));
        },

        addWarehouseMarkers(warehouses) {
            warehouses.forEach((warehouse) => {
                this.bindMarkerLabel(
                    window.L.marker(warehouse.coordinates, { title: warehouse.name }).addTo(this.warehouseLayerGroup),
                    `Warehouse: ${warehouse.name}`,
                    { muted: true },
                );
            });
        },

        bindMarkerLabel(marker, label, { muted = false } = {}) {
            const className = muted
                ? 'customer-delivery-map__label customer-delivery-map__label--muted'
                : 'customer-delivery-map__label';

            marker
                .bindPopup(this.popup(label))
                .bindTooltip(this.popup(label), {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -24],
                    className,
                });
        },

        popup(label) {
            const popup = document.createElement('span');

            popup.textContent = label;

            return popup;
        },

        fitMap(warehouses) {
            if (warehouses.length === 0) {
                this.map.setView(this.customerLocation, 16);

                return;
            }

            const bounds = window.L.latLngBounds([this.customerLocation]);

            warehouses.forEach((warehouse) => bounds.extend(warehouse.coordinates));
            this.applyMapBounds(bounds);
            requestAnimationFrame(() => this.applyMapBounds(bounds));
        },

        applyMapBounds(bounds) {
            if (! this.map) {
                return;
            }

            this.map.invalidateSize({ pan: false });
            this.map.fitBounds(bounds, {
                maxZoom: 16,
                padding: [40, 40],
            });
        },

        async drawRoute(warehouse, index, routeRevision) {
            const color = RouteColors[index % RouteColors.length];
            const geometry = await this.roadGeometry(warehouse);

            if (routeRevision !== this.routeRevision) {
                return;
            }

            if (geometry && this.map) {
                window.L.geoJSON(geometry, {
                    style: { className: 'delivery-route delivery-route--road', color, weight: 5 },
                }).addTo(this.routeLayerGroup);

                return;
            }

            this.drawFallbackRoute(warehouse, color);
        },

        async roadGeometry(warehouse) {
            const response = await this.routeResponse(warehouse);

            if (! response?.ok) {
                return null;
            }

            const payload = await this.routePayload(response);
            const geometry = payload?.routes?.[0]?.geometry;

            return geometry?.type === 'LineString' && Array.isArray(geometry.coordinates)
                ? geometry
                : null;
        },

        async routeResponse(warehouse) {
            try {
                return await fetch(this.routeEndpoint(warehouse), {
                    headers: { Accept: 'application/json' },
                    signal: this.routeAbortController.signal,
                });
            } catch (error) {
                if (error instanceof TypeError || (error instanceof DOMException && error.name === 'AbortError')) {
                    return null;
                }

                throw error;
            }
        },

        async routePayload(response) {
            try {
                return await response.json();
            } catch (error) {
                if (error instanceof SyntaxError) {
                    return null;
                }

                throw error;
            }
        },

        routeEndpoint(warehouse) {
            const baseUrl = new URL(routingServiceUrl);

            if (! ['http:', 'https:'].includes(baseUrl.protocol)) {
                throw new TypeError('The routing service URL must use HTTP or HTTPS.');
            }

            const [warehouseLatitude, warehouseLongitude] = warehouse.coordinates;
            const [customerLatitude, customerLongitude] = this.customerLocation;
            const coordinates = `${warehouseLongitude},${warehouseLatitude};${customerLongitude},${customerLatitude}`;
            const endpoint = new URL(`route/v1/driving/${coordinates}`, `${baseUrl.toString().replace(/\/$/, '')}/`);

            endpoint.searchParams.set('overview', 'full');
            endpoint.searchParams.set('geometries', 'geojson');

            return endpoint;
        },

        drawFallbackRoute(warehouse, color) {
            if (! this.map) {
                return;
            }

            window.L.polyline([warehouse.coordinates, this.customerLocation], {
                className: 'delivery-route delivery-route--fallback',
                color,
                dashArray: '8 8',
                opacity: 0.8,
                weight: 4,
            }).addTo(this.routeLayerGroup);
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
