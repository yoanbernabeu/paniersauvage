import { Controller } from '@hotwired/stimulus';

/*
 * Copies a text to the clipboard and confirms it on the button label for a moment.
 * The text comes from the "text" value, or from the value of the "source" input.
 *
 * <div data-controller="clipboard" data-clipboard-text-value="https://…">
 *     <button type="button" data-action="clipboard#copy">
 *         <span data-clipboard-target="label">Copier</span>
 *     </button>
 * </div>
 *
 * <div data-controller="clipboard" data-clipboard-copied-label-value="Lien copié">
 *     <input data-clipboard-target="source" readonly value="https://…">
 *     <button type="button" data-action="clipboard#copy"><span data-clipboard-target="label">Copier</span></button>
 * </div>
 */
export default class extends Controller {
    static targets = ['source', 'label'];

    static values = {
        text: String,
        done: String,
        copiedLabel: String,
    };

    disconnect() {
        clearTimeout(this._timeout);
    }

    async copy() {
        const text = this.hasTextValue && '' !== this.textValue
            ? this.textValue
            : (this.hasSourceTarget ? this.sourceTarget.value : '');

        try {
            await navigator.clipboard.writeText(text);
        } catch {
            this._fallbackCopy(text);
        }

        if (!this.hasLabelTarget) {
            return;
        }

        this._initialLabel ??= this.labelTarget.textContent;
        this.labelTarget.textContent = this.doneValue || this.copiedLabelValue || 'Copié !';
        clearTimeout(this._timeout);
        this._timeout = setTimeout(() => {
            this.labelTarget.textContent = this._initialLabel;
        }, 2000);
    }

    // Older browsers and non-secure contexts (plain http on a local network).
    _fallbackCopy(text) {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
    }
}
