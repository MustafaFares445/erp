const DamascusCenter = [33.5138, 36.2765];
const NominatimBaseUrl = 'https://nominatim.openstreetmap.org';

export default function customerLocationPicker({ latitude, longitude }) {
    return {
        latitude,
        longitude,
        locationStatus: 'Click the map or search for a delivery location to place a pin.',
        map: null,
        marker: null,
        searchTerm: '',

        init() {
            const initialLatitude = Number.parseFloat(this.latitude);
            const initialLongitude = Number.parseFloat(this.longitude);
            const hasInitialLocation = Number.isFinite(initialLatitude) && Number.isFinite(initialLongitude);
            const initialLocation = hasInitialLocation ? [initialLatitude, initialLongitude] : DamascusCenter;

            this.map = window.L.map(this.$refs.map).setView(initialLocation, hasInitialLocation ? 16 : 12);

            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
            }).addTo(this.map);

            this.map.on('click', ({ latlng }) => this.selectLocation(latlng.lat, latlng.lng));

            if (hasInitialLocation) {
                this.selectLocation(initialLatitude, initialLongitude);
            }

            requestAnimationFrame(() => this.map.invalidateSize());
        },

        selectLocation(latitude, longitude) {
            this.latitude = latitude.toFixed(7);
            this.longitude = longitude.toFixed(7);
            this.locationStatus = 'Delivery location selected. You can drag the pin to refine it.';

            if (this.marker) {
                this.marker.setLatLng([latitude, longitude]);

                return;
            }

            this.marker = window.L.marker([latitude, longitude], { draggable: true }).addTo(this.map);
            this.marker.on('dragend', () => {
                const position = this.marker.getLatLng();

                this.selectLocation(position.lat, position.lng);
            });
        },

        async search() {
            const query = this.searchTerm.trim();

            if (! query) {
                return;
            }

            const response = await fetch(
                `${NominatimBaseUrl}/search?format=json&limit=1&q=${encodeURIComponent(query)}`,
                { headers: { Accept: 'application/json' } },
            );

            if (! response.ok) {
                this.locationStatus = 'Location search is temporarily unavailable.';

                return;
            }

            const [searchResult] = await response.json();

            this.selectSearchResult(searchResult);
        },

        selectSearchResult(searchResult) {
            if (! searchResult) {
                this.locationStatus = 'No matching location was found.';

                return;
            }

            const latitude = Number.parseFloat(searchResult.lat);
            const longitude = Number.parseFloat(searchResult.lon);

            if (! Number.isFinite(latitude) || ! Number.isFinite(longitude)) {
                this.locationStatus = 'The selected search result has invalid coordinates.';

                return;
            }

            this.map.setView([latitude, longitude], 16);
            this.selectLocation(latitude, longitude);
        },
    };
}
