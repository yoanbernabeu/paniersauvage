/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

/*
 * Small map of the pick-up points of a shop page, next to their list (which stays the main
 * information). Same approach as producer-map: every piece of data is rendered by the server in
 * the values (App\Shop\Search\PointsMap), only the tiles of the configured server are fetched (the
 * one image origin the Content-Security-Policy allows), Leaflet's stylesheet is linked by the page
 * and the markers are div icons styled by app.css (.ps-map-marker).
 *
 * The map stays hidden without JavaScript. The page keeps the wheel and, on touch screens, the
 * one-finger scroll; each marker is focusable and opens a popup with the name and address.
 *
 * <div data-controller="points-map" data-points-map-tiles-value="{…}" data-points-map-points-value="[…]">
 *     <div data-points-map-target="frame" hidden><div data-points-map-target="canvas"></div></div>
 * </div>
 */

const MAX_ZOOM = 15;

export default class extends Controller {
    static targets = ['frame', 'canvas'];
    static values = { tiles: Object, points: Array };

    connect() {
        if (!this.hasFrameTarget || !this.hasCanvasTarget || this.pointsValue.length === 0 || !this.tilesValue.url) {
            return;
        }

        this.frameTarget.hidden = false;

        // A fresh container each time: a page restored from Turbo's cache keeps the old map's markup.
        const container = document.createElement('div');
        // Absolutely positioned: the canvas only has a min-height on wide screens, and a 100% height
        // inside it would resolve to 0 (blank map, markers stuck on the top edge).
        container.className = 'absolute inset-0';
        this.canvasTarget.replaceChildren(container);

        this.map = L.map(container, {
            scrollWheelZoom: false,
            dragging: !window.matchMedia('(pointer: coarse)').matches,
            zoomSnap: 0.5,
            attributionControl: false,
        });

        L.control.attribution({ prefix: '<a href="https://leafletjs.com">Leaflet</a>' })
            .addAttribution(this.attributionHtml())
            .addTo(this.map);
        L.tileLayer(this.tilesValue.url, { maxZoom: 19 }).addTo(this.map);

        const bounds = L.latLngBounds([]);
        this.pointsValue.forEach((point) => {
            const marker = L.marker([point.lat, point.lng], {
                icon: L.divIcon({
                    className: 'ps-map-marker',
                    html: '<span class="ps-map-marker__pin" aria-hidden="true"></span>',
                    iconSize: [32, 40],
                    iconAnchor: [16, 38],
                    popupAnchor: [0, -34],
                }),
                title: point.name,
                keyboard: true,
            });
            marker.bindPopup(() => this.popupContent(point), { className: 'ps-map-popup', maxWidth: 260, closeButton: true });
            marker.on('add', () => marker.getElement()?.setAttribute('aria-label', point.address ? `${point.name}, ${point.address}` : point.name));
            marker.addTo(this.map);
            bounds.extend(marker.getLatLng());
        });

        this.bounds = bounds;
        this.map.fitBounds(bounds, { padding: [32, 32], maxZoom: MAX_ZOOM });

        // The canvas follows the height of the list next to it: refit when it changes size.
        this.resizeObserver = new ResizeObserver(() => {
            if (!this.map) {
                return;
            }
            this.map.invalidateSize();
            this.map.fitBounds(this.bounds, { padding: [32, 32], maxZoom: MAX_ZOOM });
        });
        this.resizeObserver.observe(this.canvasTarget);
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.resizeObserver = null;
        this.map?.remove();
        this.map = null;
    }

    popupContent(point) {
        const root = document.createElement('div');
        root.className = 'ps-map-popup__body';
        root.append(element('p', 'ps-map-popup__name', point.name));
        if (point.address) {
            root.append(element('p', 'ps-map-popup__meta', point.address));
        }

        return root;
    }

    attributionHtml() {
        const text = escapeHtml(this.tilesValue.attribution);

        return this.tilesValue.attributionUrl ? `<a href="${escapeHtml(this.tilesValue.attributionUrl)}">${text}</a>` : text;
    }
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;

    return node;
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}
