import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

// Leaflet's default marker icon paths assume a specific folder layout that
// breaks under Vite's bundling — point it at the bundled asset URLs instead.
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});

// Nominatim's public API is used here for search/reverse-geocoding. It is
// rate-limited and intended for low-volume use — a self-hosted instance or a
// paid geocoding provider should replace it if this form sees real traffic.
const NOMINATIM_BASE_URL = 'https://nominatim.openstreetmap.org';
const DAMASCUS_CENTER = [33.5138, 36.2765];

document.addEventListener('DOMContentLoaded', () => {
    const contactPersonFields = document.getElementById('contact-person-fields');
    const contactIsSelfInputs = document.querySelectorAll('input[name="contact_is_self"]');

    const syncContactPersonVisibility = () => {
        const isSelf = document.querySelector('input[name="contact_is_self"]:checked')?.value === '1';
        contactPersonFields?.classList.toggle('hidden', isSelf);
    };

    contactIsSelfInputs.forEach((input) => input.addEventListener('change', syncContactPersonVisibility));
    syncContactPersonVisibility();

    const mapContainer = document.getElementById('join-us-map');

    if (!mapContainer) {
        return;
    }

    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const countryInput = document.getElementById('country');
    const cityInput = document.getElementById('city');
    const addressInput = document.getElementById('address');
    const searchInput = document.getElementById('map-search');
    const searchButton = document.getElementById('map-search-button');
    const locationSelectionStatus = document.getElementById('location-selection-status');

    const defaultZoom = Number.parseInt(mapContainer.dataset.defaultZoom ?? '15', 10);
    const map = L.map(mapContainer).setView(DAMASCUS_CENTER, defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
    }).addTo(map);

    let marker = null;

    const setMarker = (lat, lng) => {
        if (marker) {
            marker.setLatLng([lat, lng]);

            return;
        }

        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', () => {
            const position = marker.getLatLng();
            onLocationChosen(position.lat, position.lng);
        });
    };

    const onLocationChosen = async (lat, lng) => {
        latitudeInput.value = lat.toFixed(7);
        longitudeInput.value = lng.toFixed(7);
        setMarker(lat, lng);

        locationSelectionStatus.textContent = 'Delivery location selected. You can drag the pin to refine it.';

        try {
            const response = await fetch(
                `${NOMINATIM_BASE_URL}/reverse?format=json&lat=${lat}&lon=${lng}`,
                { headers: { Accept: 'application/json' } },
            );
            const data = await response.json();
            const address = data.address ?? {};

            countryInput.value = address.country ?? countryInput.value;
            cityInput.value = address.city ?? address.town ?? address.village ?? cityInput.value;

            if (data.display_name) {
                addressInput.value = data.display_name;
            }
        } catch {
            // Reverse geocoding is a convenience only — the customer can still
            // fill in country/city/address details manually.
        }
    };

    map.on('click', (event) => {
        onLocationChosen(event.latlng.lat, event.latlng.lng);
    });

    const searchAddress = async () => {
        const query = searchInput.value.trim();

        if (!query) {
            return;
        }

        try {
            const response = await fetch(
                `${NOMINATIM_BASE_URL}/search?format=json&limit=1&q=${encodeURIComponent(query)}`,
                { headers: { Accept: 'application/json' } },
            );
            const results = await response.json();

            if (results.length === 0) {
                return;
            }

            const { lat, lon } = results[0];
            const latitude = parseFloat(lat);
            const longitude = parseFloat(lon);

            map.setView([latitude, longitude], 16);
            onLocationChosen(latitude, longitude);
        } catch {
            // Search is a convenience only — the customer can still drop a
            // pin on the map manually.
        }
    };

    searchButton?.addEventListener('click', (event) => {
        event.preventDefault();
        searchAddress();
    });

    if (latitudeInput.value && longitudeInput.value) {
        const latitude = parseFloat(latitudeInput.value);
        const longitude = parseFloat(longitudeInput.value);

        map.setView([latitude, longitude], 16);
        setMarker(latitude, longitude);

        locationSelectionStatus.textContent = 'Delivery location selected. You can drag the pin to refine it.';
    }
});
