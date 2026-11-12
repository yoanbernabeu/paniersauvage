/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

/*
 * Map of the producer search (home page), next to the list it completes. Every piece of data is
 * rendered by the server in the values (App\Shop\Search\SearchMap): no extra request, only the
 * tiles of the configured server, the one image origin the Content-Security-Policy allows.
 *
 * The map stays hidden without JavaScript. Hovering or focusing a shop of the list highlights its
 * marker; opening a marker highlights the shop in the list, and scrolls it into view only when the
 * map sits beside the list (on a phone the map is above it: scrolling would push the map away).
 *
 * Keyboard: the markers are one tab stop (roving tabindex, in the order of the list); the arrows,
 * Home and End move from one shop to the next, Enter or Space opens its popup.
 *
 * Touch: one finger scrolls the page, two fingers move and zoom the map; a one-finger drag on the
 * map shows a short hint saying so.
 *
 * <div data-controller="producer-map" data-producer-map-tiles-value="{…}" data-producer-map-center-value="{…}"
 *      data-producer-map-radius-value="30" data-producer-map-results-value="[…]">
 *     <div data-producer-map-target="frame" hidden><div data-producer-map-target="canvas"></div></div>
 *     <a data-producer-map-target="item" data-result="slug"
 *        data-action="mouseenter->producer-map#highlight focusin->producer-map#highlight mouseleave->producer-map#unhighlight focusout->producer-map#unhighlight">…</a>
 * </div>
 *
 * Leaflet's stylesheet is linked by the page (a CSS import would be a data: module, refused by the
 * CSP), and the markers are div icons styled by app.css (.ps-map-marker): Leaflet's default marker
 * images are not used.
 */

// The radius circle follows the accent of the instance (--color-sage, INSTANCE_ACCENT).
const SAGE = getComputedStyle(document.documentElement).getPropertyValue('--color-sage').trim() || '#5B7B6A';
const SINGLE_RESULT_ZOOM = 13;
// Same breakpoint as the template's two-column layout (lg): the map is then beside the list.
const SIDE_BY_SIDE = '(min-width: 64rem)';
const HINT_DURATION_MS = 1600;

export default class extends Controller {
    static targets = ['frame', 'canvas', 'item'];
    static values = { tiles: Object, center: Object, radius: Number, results: Array };

    connect() {
        if (!this.hasFrameTarget || !this.hasCanvasTarget || this.resultsValue.length === 0 || !this.tilesValue.url) {
            return;
        }

        this.markers = new Map();
        this.order = this.resultsValue.map((result) => result.slug);
        // The one marker in the tab order (roving tabindex): the nearest shop until another gets the focus.
        this.tabStop = this.order[0];
        this.selected = null;
        this.frameTarget.hidden = false;
        this.element.dataset.map = 'on';

        // A fresh container each time: a page restored from Turbo's cache keeps the old map's markup.
        const container = document.createElement('div');
        container.className = 'size-full';
        this.canvasTarget.replaceChildren(container);

        const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
        this.map = L.map(container, {
            // The page keeps the wheel and, on touch screens, the one-finger scroll: the map moves
            // with its buttons, the keyboard, a mouse drag or a two-finger pinch.
            scrollWheelZoom: false,
            dragging: !coarsePointer,
            zoomSnap: 0.5,
            attributionControl: false,
        });

        L.control.attribution({ prefix: '<a href="https://leafletjs.com">Leaflet</a>' })
            .addAttribution(this.attributionHtml())
            .addTo(this.map);

        L.tileLayer(this.tilesValue.url, { maxZoom: 19 }).addTo(this.map);

        const center = L.latLng(this.centerValue.lat, this.centerValue.lng);
        L.circle(center, {
            radius: this.radiusValue * 1000,
            color: SAGE,
            weight: 1.5,
            opacity: 0.55,
            dashArray: '4 6',
            fillColor: SAGE,
            fillOpacity: 0.05,
            interactive: false,
        }).addTo(this.map);

        L.marker(center, {
            icon: L.divIcon({ className: 'ps-map-center', html: '<span></span>', iconSize: [22, 22], iconAnchor: [11, 11] }),
            // Named on hover only: a permanent label would hide the shops right next to the centre.
            // The legend under the map says what the dark dot is.
            keyboard: false,
            zIndexOffset: -1000,
        })
            .bindTooltip(this.centerValue.label, { direction: 'top', offset: [0, -10], className: 'ps-map-center-label' })
            .addTo(this.map);

        const bounds = L.latLngBounds([center]);
        this.resultsValue.forEach((result) => {
            const marker = this.addShopMarker(result);
            this.markers.set(result.slug, marker);
            bounds.extend(marker.getLatLng());
        });

        if (coarsePointer) {
            this.addTouchHint(container);
        }

        this.map.fitBounds(bounds, { padding: [36, 36], maxZoom: SINGLE_RESULT_ZOOM });

        // Escape closes the open popup wherever the focus is in the map (Leaflet only listens when
        // the map container itself has the focus).
        container.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.selected !== null) {
                this.markers.get(this.selected)?.closePopup();
            }
        });
    }

    disconnect() {
        clearTimeout(this.hintTimer);
        this.map?.remove();
        this.map = null;
        this.markers?.clear();
    }

    highlight(event) {
        this.markerElement(event.currentTarget.dataset.result)?.classList.add('is-hovered');
        this.markers?.get(event.currentTarget.dataset.result)?.setZIndexOffset(1000);
    }

    unhighlight(event) {
        const slug = event.currentTarget.dataset.result;
        this.markerElement(slug)?.classList.remove('is-hovered');
        if (slug !== this.selected) {
            this.markers?.get(slug)?.setZIndexOffset(0);
        }
    }

    addShopMarker(result) {
        const label = `${result.name}, ${result.distance}`;
        const marker = L.marker([result.lat, result.lng], {
            icon: L.divIcon({
                className: 'ps-map-marker',
                html: '<span class="ps-map-marker__pin" aria-hidden="true"></span>',
                iconSize: [32, 40],
                iconAnchor: [16, 38],
                popupAnchor: [0, -34],
            }),
            title: result.name,
            keyboard: true,
            riseOnHover: true,
        });

        marker.bindPopup(() => this.popupContent(result), { className: 'ps-map-popup', maxWidth: 260, minWidth: 200, closeButton: true });
        marker.on('add', () => {
            const icon = marker.getElement();
            icon?.setAttribute('aria-label', label);
            // Roving tabindex: setTabStop() puts a single marker in the tab order.
            // Leaflet adds the markers once the map has a view (fitBounds), after this loop.
            icon?.setAttribute('tabindex', result.slug === this.tabStop ? '0' : '-1');
            icon?.addEventListener('focus', () => this.setTabStop(result.slug));
            // Leaflet opens the popup on Enter (keypress): note it beforehand, and add Space.
            icon?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    this.openedByKeyboard = true;
                }
                if (event.key === ' ') {
                    event.preventDefault();
                    marker.openPopup();
                }
                const next = this.neighbour(result.slug, event.key);
                if (next !== null) {
                    // Not to the map: its own keyboard handler would pan it.
                    event.preventDefault();
                    event.stopPropagation();
                    this.markerElement(next)?.focus();
                }
            });
        });
        marker.on('popupopen', (event) => this.select(result.slug, event.popup));
        marker.on('popupclose', () => this.deselect(result.slug));

        return marker.addTo(this.map);
    }

    select(slug, popup) {
        this.selected = slug;
        this.markerElement(slug)?.classList.add('is-active');
        this.markers.get(slug)?.setZIndexOffset(1000);

        this.itemTargets.forEach((item) => {
            if (item.dataset.result === slug) {
                item.dataset.active = '';
                if (window.matchMedia(SIDE_BY_SIDE).matches) {
                    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    item.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'nearest' });
                }
            } else {
                delete item.dataset.active;
            }
        });

        // A marker opened with the keyboard hands the focus to the popup, and gets it back on close.
        this.returnFocus = this.openedByKeyboard ? slug : null;
        if (this.openedByKeyboard) {
            popup.getElement()?.querySelector('a')?.focus({ preventScroll: true });
        }
        this.openedByKeyboard = false;
    }

    deselect(slug) {
        this.markerElement(slug)?.classList.remove('is-active');
        this.markers?.get(slug)?.setZIndexOffset(0);
        this.itemTargets.forEach((item) => {
            if (item.dataset.result === slug) {
                delete item.dataset.active;
            }
        });
        if (this.selected === slug) {
            this.selected = null;
        }
        if (this.returnFocus === slug) {
            this.returnFocus = null;
            this.markerElement(slug)?.focus({ preventScroll: true });
        }
    }

    /** Slug of the shop an arrow, Home or End key leads to from `slug`, in the order of the list. */
    neighbour(slug, key) {
        const index = this.order.indexOf(slug);
        const last = this.order.length - 1;
        const target = {
            ArrowRight: Math.min(index + 1, last),
            ArrowDown: Math.min(index + 1, last),
            ArrowLeft: Math.max(index - 1, 0),
            ArrowUp: Math.max(index - 1, 0),
            Home: 0,
            End: last,
        }[key];

        return target === undefined ? null : this.order[target];
    }

    setTabStop(slug) {
        this.tabStop = slug;
        this.order.forEach((each) => this.markerElement(each)?.setAttribute('tabindex', each === slug ? '0' : '-1'));
    }

    addTouchHint(container) {
        const hint = element('div', 'ps-map-hint', 'Utilise deux doigts pour déplacer la carte');
        // A visual cue for a touch gesture only: screen readers read the legend instead.
        hint.setAttribute('aria-hidden', 'true');
        container.append(hint);

        let oneFinger = false;
        container.addEventListener('touchstart', (event) => {
            oneFinger = event.touches.length === 1;
        }, { passive: true });
        container.addEventListener('touchmove', (event) => {
            if (!oneFinger || event.touches.length !== 1) {
                hint.classList.remove('is-visible');
                return;
            }
            hint.classList.add('is-visible');
            clearTimeout(this.hintTimer);
            this.hintTimer = setTimeout(() => hint.classList.remove('is-visible'), HINT_DURATION_MS);
        }, { passive: true });
    }

    popupContent(result) {
        const root = document.createElement('div');
        root.className = 'ps-map-popup__body';

        const head = element('div', 'ps-map-popup__head');
        if (result.logo) {
            const logo = element('img', 'ps-map-popup__logo');
            logo.src = result.logo;
            logo.alt = '';
            logo.width = 40;
            logo.height = 40;
            head.append(logo);
        }
        head.append(element('p', 'ps-map-popup__name', result.name));
        root.append(head);

        root.append(element('p', 'ps-map-popup__meta', `${result.distance} · ${result.point}`));
        root.append(element('p', 'ps-map-popup__sale', result.nextSale ?? 'Pas de vente prévue pour l’instant'));

        const link = element('a', 'ps-map-popup__link', 'Voir la boutique');
        link.href = result.url;
        link.setAttribute('aria-label', `Voir la boutique ${result.name}`);
        root.append(link);

        return root;
    }

    attributionHtml() {
        const text = escapeHtml(this.tilesValue.attribution);

        return this.tilesValue.attributionUrl ? `<a href="${escapeHtml(this.tilesValue.attributionUrl)}">${text}</a>` : text;
    }

    markerElement(slug) {
        return this.markers?.get(slug)?.getElement() ?? null;
    }
}

function element(tag, className, text = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== null) {
        node.textContent = text;
    }

    return node;
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}
