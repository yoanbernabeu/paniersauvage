import { Controller } from '@hotwired/stimulus';

/*
 * Button that shows / hides a panel (the "Plus" menu of the mobile producer navigation).
 * Keeps aria-expanded in sync, closes on Escape (focus back on the button), on a click outside
 * and before Turbo caches the page. Without JavaScript the panel stays hidden: keep a fallback link.
 *
 * <div data-controller="disclosure">
 *     <button type="button" aria-expanded="false" aria-controls="menu"
 *             data-disclosure-target="button" data-action="disclosure#toggle">Plus</button>
 *     <div id="menu" hidden data-disclosure-target="panel">…</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this._onKeydown = this._onKeydown.bind(this);
        this._onClickOutside = this._onClickOutside.bind(this);
        this._close = this.close.bind(this);
        document.addEventListener('turbo:before-cache', this._close);
    }

    disconnect() {
        this._unlisten();
        document.removeEventListener('turbo:before-cache', this._close);
    }

    toggle() {
        this.panelTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.panelTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        document.addEventListener('keydown', this._onKeydown);
        document.addEventListener('click', this._onClickOutside, true);
        this.panelTarget.querySelector('a, button')?.focus();
    }

    close() {
        if (!this.hasPanelTarget || this.panelTarget.hidden) {
            return;
        }

        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this._unlisten();
    }

    _onKeydown(event) {
        if ('Escape' === event.key) {
            event.preventDefault();
            this.close();
            this.buttonTarget.focus();
        }
    }

    _onClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    _unlisten() {
        document.removeEventListener('keydown', this._onKeydown);
        document.removeEventListener('click', this._onClickOutside, true);
    }
}
