import { Controller } from '@hotwired/stimulus';

/*
 * Saves a small settings form as soon as a field changes. The submit button stays for visitors
 * without JavaScript: the controller hides it once connected.
 *
 * <form data-controller="autosubmit" data-action="change->autosubmit#submit">
 *     <input type="checkbox" …>
 *     <button type="submit" data-autosubmit-target="fallback">Enregistrer</button>
 * </form>
 */
export default class extends Controller {
    static targets = ['fallback'];

    connect() {
        this.fallbackTargets.forEach((button) => { button.hidden = true; });
    }

    submit() {
        this.element.requestSubmit();
    }
}
