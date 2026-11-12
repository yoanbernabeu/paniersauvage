import { Controller } from '@hotwired/stimulus';

/*
 * Product form: shows the "sold by weight" settings only when the unit is the kilogram.
 * Without JavaScript the panel stays visible (its text says it only applies to products sold by weight).
 *
 * <div data-controller="weighed-fields">
 *   <select data-weighed-fields-target="unit" data-action="weighed-fields#toggle">…</select>
 *   <fieldset data-weighed-fields-target="panel">…</fieldset>
 * </div>
 */
export default class extends Controller {
    static targets = ['unit', 'panel'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (!this.hasUnitTarget || !this.hasPanelTarget) {
            return;
        }

        this.panelTarget.hidden = 'kg' !== this.unitTarget.value;
    }
}
