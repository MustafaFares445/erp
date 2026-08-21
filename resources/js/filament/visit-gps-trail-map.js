export default function visitGpsTrailMap({ points, customerLocation }) {
    return {
        map: null,
        resizeObserver: null,

        init() {
            const path = this.parsePoints(points);
            const customer = this.parsePoint(customerLocation);

            if (path.length === 0 && !customer) {
                return;
            }

            this.createMap(path, customer);
        },

        parsePoints(rawPoints) {
            if (!Array.isArray(rawPoints)) {
                return [];
            }

            return rawPoints.map((point) => this.parsePoint(point)).filter(Boolean);
        },

        parsePoint(point) {
            const latitude = Number.parseFloat(point?.latitude);
            const longitude = Number.parseFloat(point?.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                return null;
            }

            return { latLng: [latitude, longitude], recordedAt: point?.recordedAt ?? null, label: point?.label ?? null };
        },

        createMap(path, customer) {
            const anchor = path[0]?.latLng ?? customer.latLng;

            this.map = window.L.map(this.$refs.map, {
                scrollWheelZoom: false,
            }).setView(anchor, 16);

            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
            }).addTo(this.map);

            if (path.length > 1) {
                window.L.polyline(path.map((point) => point.latLng), {
                    className: 'visit-gps-trail-map__line',
                    color: '#2563eb',
                    weight: 4,
                    opacity: 0.85,
                }).addTo(this.map);
            }

            path.forEach((point, index) => this.addMarker(point, index, path.length));

            if (customer) {
                this.addCustomerMarker(customer);
            }

            const boundsPoints = customer ? [...path, customer] : path;
            const bounds = window.L.latLngBounds(boundsPoints.map((point) => point.latLng));
            this.resizeObserver = new ResizeObserver(() => this.map?.invalidateSize());
            this.resizeObserver.observe(this.$refs.map);

            requestAnimationFrame(() => {
                this.map?.invalidateSize();
                this.map?.fitBounds(bounds, { maxZoom: 17, padding: [24, 24] });
            });
        },

        addMarker(point, index, total) {
            const isStart = index === 0;
            const isEnd = total > 1 && index === total - 1;
            const color = isStart ? '#16a34a' : (isEnd ? '#dc2626' : '#2563eb');
            const label = isStart ? 'Checked in' : (isEnd ? 'Checked out' : 'GPS point');

            const marker = window.L.circleMarker(point.latLng, {
                radius: isStart || isEnd ? 8 : 5,
                color,
                fillColor: color,
                fillOpacity: 1,
                weight: 2,
            })
                .addTo(this.map)
                .bindPopup(this.popupText(label, point.recordedAt));

            marker.on('mouseover', () => marker.openPopup());
            marker.on('mouseout', () => marker.closePopup());
        },

        addCustomerMarker(customer) {
            const marker = window.L.marker(customer.latLng, {
                icon: window.L.divIcon({
                    className: 'visit-gps-trail-map__customer-icon',
                    html: '<span>&#127970;</span>',
                    iconSize: [28, 28],
                    iconAnchor: [14, 28],
                }),
            })
                .addTo(this.map)
                .bindPopup(customer.label ?? 'Customer location');

            marker.on('mouseover', () => marker.openPopup());
            marker.on('mouseout', () => marker.closePopup());
        },

        popupText(label, recordedAt) {
            const time = this.formatTime(recordedAt);

            return time ? `${label} — ${time}` : label;
        },

        formatTime(isoString) {
            if (!isoString) {
                return '';
            }

            const date = new Date(isoString);

            return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
        },

        destroy() {
            this.resizeObserver?.disconnect();
            this.map?.remove();
            this.map = null;
        },
    };
}
