import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { generateCsrfHeaders, generateCsrfToken, removeCsrfToken } from './csrf_protection_controller.js';

const normalize = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

const NBSP = ' ';
const NARROW_NBSP = ' ';
/** Heaviest weight of one line, in thousandths (App\Ordering\Service\OrderWeighing::MAX_WEIGHT). */
const MAX_WEIGHT = 999000;
const RETRY_DELAY = 15000;
const REQUEST_TIMEOUT = 10000;

/* ---- Formatting, the same as App\Shared\Service\FrenchFormatter and Quantity ------------------ */

/** 1500 → "1,5", 2000 → "2". */
const decimal = (quantity) => {
    const whole = Math.floor(quantity / 1000);
    const fraction = String(quantity % 1000).padStart(3, '0').replace(/0+$/, '');

    return '' === fraction ? String(whole) : `${whole},${fraction}`;
};
const formatQuantity = (quantity, weighed) => (weighed ? `${decimal(quantity)}${NBSP}kg` : decimal(quantity));
/** 123456 → "1 234,56 €" (narrow no-break space between thousands). */
const money = (cents) => {
    const euros = String(Math.floor(Math.abs(cents) / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, NARROW_NBSP);
    const rest = String(Math.abs(cents) % 100).padStart(2, '0');

    return `${cents < 0 ? '-' : ''}${euros},${rest}${NBSP}€`;
};
/** Unit price × quantity (thousandths), rounded to the cent, halves up (Quantity::priceCents()). */
const priceCents = (quantity, unitPriceCents) => Math.floor((2 * quantity * unitPriceCents + 1000) / 2000);
/**
 * "1,25" or "1.25" → 1250 (Quantity::parse()); "" → null (no weight); undefined when it is not a weight.
 */
const parseWeight = (value) => {
    const cleaned = value.replace(/[\s  ]/g, '').replace(',', '.');
    if ('' === cleaned) {
        return null;
    }
    const match = /^(\d{1,6})(?:\.(\d{0,3}))?$/.exec(cleaned);

    return match ? parseInt(match[1] + (match[2] || '').padEnd(3, '0'), 10) : undefined;
};
const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;

const uuid = () => {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

/*
 * Delivery sheet (SPEC §20): "to deliver / delivered" tabs, customer search, and offline work.
 *
 * Every tick, "not delivered", "back to deliver" and weight is applied on screen at once, written
 * to a queue kept in the browser (localStorage, or memory when it is blocked), then sent to
 * `pro_order_sheet_action` one after the other, in order. Each action carries a unique id (a replay
 * never counts twice) and the state the producer saw (the server refuses it when the order was
 * cancelled or changed in the meantime: the conflict is listed on the sheet). Without network the
 * queue waits and is sent again when it comes back. A service worker keeps the sheet and its assets
 * to reopen it without network.
 *
 * Without JavaScript the forms post as usual, and both lists follow each other.
 */
export default class extends Controller {
    static targets = [
        'search', 'item', 'tablist', 'tab', 'panel', 'heading', 'noMatch',
        'syncBar', 'sync', 'syncLabel', 'login', 'conflicts', 'conflictList',
        'progressLabel', 'progress', 'progressBar', 'finished', 'truckItem',
    ];

    static values = { queueKey: String, csrf: String, worker: String, scope: String };

    connect() {
        this.currentTab = this.currentTab || 'todo';
        this.memory = {};
        this.state = false === navigator.onLine ? 'offline' : 'saved';
        this.applyPending();
        this.refresh();
        this.sync();
        this.registerWorker();
    }

    disconnect() {
        clearTimeout(this.retryTimer);
    }

    /* ---- Tabs and search ------------------------------------------------------------------- */

    select(event) {
        this.currentTab = event.currentTarget.dataset.tab;
        this.refresh();
    }

    filter() {
        this.refresh();
    }

    // WAI-ARIA tabs: arrows move between tabs (and select them), Home / End go to the ends.
    navigate(event) {
        const tabs = this.tabTargets;
        const index = tabs.indexOf(event.currentTarget);
        const next = {
            ArrowRight: (index + 1) % tabs.length,
            ArrowLeft: (index - 1 + tabs.length) % tabs.length,
            Home: 0,
            End: tabs.length - 1,
        }[event.key];

        if (undefined === next) {
            return;
        }

        event.preventDefault();
        this.currentTab = tabs[next].dataset.tab;
        this.refresh();
        tabs[next].focus();
    }

    /**
     * Re-applies everything the page shows from its cards: tabs, filter, counters, progress, truck
     * load, saving state. Also called after Turbo morphed the page.
     */
    refresh() {
        const query = this.hasSearchTarget ? normalize(this.searchTarget.value) : '';

        this.tablistTargets.forEach((tablist) => { tablist.hidden = false; });
        this.headingTargets.forEach((heading) => { heading.classList.add('sr-only'); });
        this.tabTargets.forEach((tab) => {
            const selected = tab.dataset.tab === this.currentTab;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });
        this.itemTargets.forEach((item) => {
            item.hidden = '' !== query && !normalize(item.dataset.search || '').includes(query);
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.tab !== this.currentTab;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', `tab-${panel.dataset.tab}`);
            panel.tabIndex = 0;

            const items = this.itemTargets.filter((item) => panel.contains(item));
            const empty = panel.querySelector('[data-empty-list]');
            if (empty) {
                empty.hidden = 0 !== items.length;
            }
            const noMatch = this.noMatchTargets.find((message) => panel.contains(message));
            if (noMatch) {
                noMatch.hidden = 0 === items.length || items.some((item) => !item.hidden);
            }
        });

        this.refreshCounters();
        this.renderSync();
        this.renderConflicts();
    }

    refreshCounters() {
        const delivered = this.itemTargets.filter((item) => 'delivered' === item.dataset.orderStatus);
        const done = delivered.length;
        const total = this.itemTargets.length;
        const counts = { todo: total - done, done };

        this.element.querySelectorAll('[data-count]').forEach((counter) => {
            counter.textContent = String(counts[counter.dataset.count] ?? 0);
        });
        if (this.hasProgressLabelTarget) {
            this.progressLabelTarget.textContent = `${done} livrée${done > 1 ? 's' : ''} sur ${total}`;
        }
        if (this.hasProgressTarget) {
            this.progressTarget.setAttribute('aria-valuenow', String(done));
            this.progressTarget.setAttribute('aria-valuemax', String(total));
        }
        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = `${total ? Math.round((done * 100) / total) : 0}%`;
        }
        if (this.hasFinishedTarget) {
            this.finishedTarget.hidden = done !== total;
        }

        this.truckItemTargets.forEach((truckItem) => {
            let remaining = 0;
            this.itemTargets.forEach((item) => {
                if ('delivered' !== item.dataset.orderStatus) {
                    item.querySelectorAll(`[data-line][data-offer="${truckItem.dataset.offer}"]`).forEach((line) => {
                        remaining += parseInt(line.dataset.quantity, 10) || 0;
                    });
                }
            });
            const label = truckItem.querySelector('[data-truck-remaining]');
            if (label) {
                label.hidden = remaining === parseInt(truckItem.dataset.total, 10);
                label.textContent = `(reste ${formatQuantity(remaining, '1' === truckItem.dataset.weighed)})`;
            }
        });
    }

    /* ---- Actions --------------------------------------------------------------------------- */

    /**
     * A delivery button of a card: delivered, not delivered, back to deliver.
     */
    mark(event) {
        const status = event.submitter?.value;
        const item = event.target.closest('[data-order-id]');
        if (!status || !item) {
            return;
        }
        event.preventDefault();

        const expected = item.dataset.orderStatus;
        if (expected === status) {
            return;
        }

        const next = this.nextFocus(item);
        this.enqueue({ type: 'mark', status, expected }, item);
        this.setStatus(item, status);
        this.refresh();
        (this.visibleCheckbox(item) || next)?.focus({ preventScroll: true });
        this.sync();
    }

    /**
     * The "Poids réel" form of a card.
     */
    weigh(event) {
        const form = event.target;
        const item = form.closest('[data-order-id]');
        if (!item) {
            return;
        }
        event.preventDefault();

        const weights = {};
        const expectedWeights = {};
        let invalid = null;
        form.querySelectorAll('[data-line-input]').forEach((input) => {
            const lineId = input.dataset.lineInput;
            const weight = parseWeight(input.value);
            const wrong = undefined === weight || (null !== weight && (weight <= 0 || weight > MAX_WEIGHT));
            input.toggleAttribute('aria-invalid', wrong);
            if (wrong) {
                invalid ??= input;
                return;
            }
            weights[lineId] = weight;
            expectedWeights[lineId] = this.actualOf(item.querySelector(`[data-line="${lineId}"]`));
        });

        const error = form.querySelector('[data-weigh-error]');
        if (invalid) {
            if (error) {
                error.textContent = 'Indique un poids en kilos entre 0,001 et 999, par exemple 1,25.';
                error.hidden = false;
            }
            invalid.focus();
            return;
        }
        if (error) {
            error.hidden = true;
        }

        if (Object.keys(weights).every((lineId) => weights[lineId] === expectedWeights[lineId])) {
            return;
        }

        this.enqueue({ type: 'weigh', weights, expectedWeights }, item);
        this.setWeights(item, weights);
        this.sync();
    }

    dismissConflicts() {
        this.write('conflicts', []);
        this.renderConflicts();
    }

    /* ---- Queue ----------------------------------------------------------------------------- */

    enqueue(action, item) {
        const queue = this.read('queue');
        queue.push({
            id: uuid(),
            url: item.dataset.actionUrl,
            order: item.dataset.orderId,
            name: item.dataset.orderName,
            ...action,
        });
        this.write('queue', queue);
    }

    async sync() {
        clearTimeout(this.retryTimer);
        if (this.syncing) {
            return;
        }
        if (0 === this.read('queue').length) {
            this.showState(false === navigator.onLine ? 'offline' : 'saved');
            return;
        }
        if (false === navigator.onLine) {
            this.showState('offline');
            return;
        }

        this.syncing = true;
        try {
            // One tab at a time sends the queue (the others see it shrink through the storage event).
            if (navigator.locks?.request) {
                await navigator.locks.request(this.queueKeyValue, () => this.drain());
            } else {
                await this.drain();
            }
        } finally {
            this.syncing = false;
        }
    }

    async drain() {
        let uncertain = false;

        for (;;) {
            const [action] = this.read('queue');
            if (!action) {
                break;
            }
            this.showState('pending');

            let response;
            try {
                response = await this.send(action);
            } catch {
                this.showState('offline');
                this.retryLater();
                return;
            }

            if ('opaqueredirect' === response.type || 401 === response.status || 403 === response.status) {
                // Signed out (or the session expired): the queue waits for the producer to sign in again.
                this.showState('session');
                return;
            }
            if (429 === response.status || response.status >= 500) {
                this.showState('pending');
                this.retryLater();
                return;
            }

            let body = null;
            try {
                body = await response.json();
            } catch {
                body = null;
            }

            this.write('queue', this.read('queue').filter((queued) => queued.id !== action.id));
            if (response.ok && body?.order) {
                if ('conflict' === body.outcome) {
                    this.addConflict(body.message);
                }
                this.reconcile(body.order);
            } else {
                // Refused for good (unknown order, impossible weight): dropped, the page is reloaded.
                this.addConflict(body?.message || `Une action sur la commande de ${action.name} n'a pas pu être enregistrée.`);
                uncertain = true;
            }
        }

        this.showState('saved');
        if (uncertain) {
            Turbo.visit(window.location.href, { action: 'replace' });
        }
    }

    async send(action) {
        const payload = 'mark' === action.type
            ? { id: action.id, type: action.type, status: action.status, expected: action.expected }
            : { id: action.id, type: action.type, weights: action.weights, expectedWeights: action.expectedWeights };

        // The same double-submitted CSRF token as the forms (cookie + header), on a detached field.
        const form = document.createElement('form');
        const field = document.createElement('input');
        field.name = '_csrf_token';
        field.defaultValue = this.csrfValue; // not `value`: the helper replaces the default value
        form.append(field);
        generateCsrfToken(form);

        try {
            return await fetch(action.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Csrf-Token': field.value,
                    ...generateCsrfHeaders(form),
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
                redirect: 'manual',
                signal: AbortSignal.timeout ? AbortSignal.timeout(REQUEST_TIMEOUT) : undefined,
            });
        } finally {
            removeCsrfToken(form);
        }
    }

    retryLater() {
        clearTimeout(this.retryTimer);
        this.retryTimer = setTimeout(() => this.sync(), RETRY_DELAY);
    }

    wentOffline() {
        if (!this.syncing) {
            this.showState('offline');
        }
    }

    /**
     * Another tab changed the queue: show its pending actions here too.
     */
    storageChanged(event) {
        if (null === event.key || event.key.startsWith(this.queueKeyValue)) {
            this.applyPending();
            this.refresh();
        }
    }

    /**
     * Puts the queued actions back on screen (page reopened from the cache, morphed by Turbo).
     */
    applyPending() {
        this.read('queue').forEach((action) => {
            const item = this.itemFor(action.order);
            if (!item) {
                return;
            }
            if ('mark' === action.type) {
                this.setStatus(item, action.status);
            } else {
                this.setWeights(item, action.weights);
            }
        });
    }

    /**
     * The order as the server now sees it, unless more actions on it are still waiting.
     */
    reconcile(order) {
        const item = this.itemFor(String(order.id));
        if (!item || this.read('queue').some((action) => action.order === String(order.id))) {
            return;
        }
        this.setStatus(item, order.status);
        this.setWeights(item, order.weights || {}, order);
        this.refresh();
    }

    /* ---- Cards ----------------------------------------------------------------------------- */

    itemFor(orderId) {
        return this.itemTargets.find((item) => item.dataset.orderId === String(orderId));
    }

    setStatus(item, status) {
        item.dataset.orderStatus = status;
        this.showButtons(item, status);

        const list = this.element.querySelector(`[data-list="${'delivered' === status ? 'done' : 'todo'}"]`);
        if (list && item.parentElement !== list) {
            const position = parseInt(item.dataset.position, 10) || 0;
            const before = Array.from(list.children).find((other) => (parseInt(other.dataset.position, 10) || 0) > position);
            list.insertBefore(item, before || null);
        }
    }

    showButtons(item, status) {
        item.querySelectorAll('[data-when]').forEach((element) => {
            element.hidden = !element.dataset.when.split(' ').includes(status);
        });
    }

    /**
     * Shows the weights and the amount that follows (`server`: the order as the server sent it).
     */
    setWeights(item, weights, server = null) {
        Object.entries(weights).forEach(([lineId, weight]) => {
            const line = item.querySelector(`[data-line="${lineId}"]`);
            if (!line) {
                return;
            }
            line.dataset.actual = null === weight ? '' : String(weight);
            const label = line.querySelector('[data-line-weight]');
            if (label) {
                label.textContent = null === weight ? '' : ` · pesé ${formatQuantity(weight, true)}`;
            }
            const input = item.querySelector(`[data-line-input="${lineId}"]`);
            if (input && document.activeElement !== input) {
                input.value = null === weight ? '' : decimal(weight);
            }
        });

        const total = item.querySelector('[data-order-total]');
        if (!total) {
            return;
        }
        if (server) {
            total.textContent = `${server.estimated ? 'environ ' : ''}${server.total}`;
            return;
        }
        let cents = 0;
        let estimated = false;
        item.querySelectorAll('[data-line]').forEach((line) => {
            const actual = this.actualOf(line);
            estimated ||= '1' === line.dataset.weighed && null === actual;
            cents += priceCents(actual ?? (parseInt(line.dataset.quantity, 10) || 0), parseInt(line.dataset.unitPrice, 10) || 0);
        });
        total.textContent = `${estimated ? 'environ ' : ''}${money(cents)}`;
    }

    actualOf(line) {
        return !line || '' === (line.dataset.actual ?? '') ? null : parseInt(line.dataset.actual, 10);
    }

    visibleCheckbox(item) {
        const panel = item.closest('[data-delivery-sheet-target="panel"]');
        if (panel?.hidden) {
            return null;
        }

        return Array.from(item.querySelectorAll('button[role="checkbox"]')).find((button) => !button.hidden) || null;
    }

    /**
     * Where the focus goes when the ticked card leaves the list: the next card, or the tab.
     */
    nextFocus(item) {
        const sibling = item.nextElementSibling || item.previousElementSibling;
        const button = sibling ? Array.from(sibling.querySelectorAll('button[role="checkbox"]')).find((candidate) => !candidate.hidden) : null;

        return button || this.tabTargets.find((tab) => tab.dataset.tab === this.currentTab) || null;
    }

    /* ---- Saving state and conflicts -------------------------------------------------------- */

    showState(state) {
        this.state = state;
        this.renderSync();
    }

    renderSync() {
        if (!this.hasSyncTarget) {
            return;
        }
        const count = this.read('queue').length;
        const actions = plural(count, 'action');
        const label = {
            saved: 'Tout est enregistré',
            pending: `Enregistrement… ${actions} en attente`,
            offline: count ? `Hors ligne : ${actions} en attente` : 'Hors ligne : tes coches partiront au retour du réseau',
            session: `Session expirée : ${actions} en attente. Reconnecte-toi pour les enregistrer.`,
        }[this.state];

        this.syncBarTargets.forEach((bar) => { bar.hidden = false; });
        this.syncTarget.dataset.syncState = this.state;
        this.syncTarget.querySelectorAll('[data-sync-icon]').forEach((icon) => {
            icon.hidden = !icon.dataset.syncIcon.split(' ').includes(this.state);
        });
        if (this.syncLabelTarget.textContent !== label) {
            this.syncLabelTarget.textContent = label;
        }
        this.loginTargets.forEach((link) => { link.hidden = 'session' !== this.state; });
    }

    addConflict(message) {
        if (!message) {
            return;
        }
        this.write('conflicts', [...this.read('conflicts'), message]);
        this.renderConflicts();
    }

    renderConflicts() {
        if (!this.hasConflictsTarget) {
            return;
        }
        const conflicts = this.read('conflicts');
        this.conflictListTarget.replaceChildren(...conflicts.map((message) => {
            const entry = document.createElement('li');
            entry.textContent = message;

            return entry;
        }));
        this.conflictsTarget.hidden = 0 === conflicts.length;
    }

    /* ---- Storage (localStorage when allowed, memory otherwise) ------------------------------ */

    read(name) {
        try {
            const stored = window.localStorage.getItem(`${this.queueKeyValue}:${name}`);
            if (null !== stored) {
                const list = JSON.parse(stored);

                return Array.isArray(list) ? list : [];
            }
        } catch {
            // Blocked or corrupted storage: fall back to memory.
        }

        return [...(this.memory[name] || [])];
    }

    write(name, list) {
        this.memory[name] = list;
        try {
            window.localStorage.setItem(`${this.queueKeyValue}:${name}`, JSON.stringify(list));
        } catch {
            // Private mode or full storage: the queue lives in memory until the page is closed.
        }
    }

    /* ---- Offline copy of the sheet ----------------------------------------------------------- */

    async registerWorker() {
        if (!('serviceWorker' in navigator) || !this.workerValue) {
            return;
        }
        try {
            const controlled = null !== navigator.serviceWorker.controller;
            await navigator.serviceWorker.register(this.workerValue, { scope: this.scopeValue });
            const registration = await navigator.serviceWorker.ready;
            registration.active?.postMessage({ type: 'cache-sheet', urls: this.urlsToCache(controlled) });
        } catch {
            // No offline copy (private mode, old browser): the PDF stays the fallback.
        }
    }

    /**
     * The sheet itself (unless the worker already served it) and the assets it loaded.
     */
    urlsToCache(controlled) {
        const urls = new Set(controlled ? [] : [window.location.href]);
        performance.getEntriesByType('resource').forEach((entry) => urls.add(entry.name));
        document.querySelectorAll('link[rel="modulepreload"][href], link[rel="stylesheet"][href], link[rel="preload"][href], script[src]').forEach((element) => {
            urls.add(element.href || element.src);
        });

        return Array.from(urls);
    }
}
