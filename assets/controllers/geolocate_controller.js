import { Controller } from '@hotwired/stimulus';

/*
 * "Autour de moi" button of the producer search. Asks the browser for its position, rounds it to
 * about a hundred metres and submits the search form with `lat` and `lng` instead of the typed
 * place. The position is only sent in the URL of that search: nothing is stored.
 *
 * The button stays hidden without JavaScript or without the Geolocation API (it needs HTTPS, or
 * localhost in development). Typing a place disables the coordinates again.
 *
 * <form data-controller="geolocate">
 *     <input name="ou" data-geolocate-target="place" data-action="input->geolocate#forgetPosition">
 *     <input type="hidden" name="lat" data-geolocate-target="latitude" disabled>
 *     <input type="hidden" name="lng" data-geolocate-target="longitude" disabled>
 *     <button type="button" hidden data-geolocate-target="button" data-action="geolocate#locate">Autour de moi</button>
 *     <p data-geolocate-target="status" aria-live="polite"></p>
 * </form>
 */
export default class extends Controller {
    static targets = ['place', 'latitude', 'longitude', 'button', 'status'];

    connect() {
        if ('geolocation' in navigator) {
            this.buttonTarget.hidden = false;
        }
    }

    locate() {
        this.buttonTarget.disabled = true;
        this.say('On cherche où tu es…');

        navigator.geolocation.getCurrentPosition(
            (position) => this.submitPosition(position.coords),
            (error) => this.fail(error),
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 },
        );
    }

    forgetPosition() {
        this.latitudeTarget.disabled = true;
        this.longitudeTarget.disabled = true;
    }

    submitPosition(coords) {
        this.latitudeTarget.value = coords.latitude.toFixed(3);
        this.longitudeTarget.value = coords.longitude.toFixed(3);
        this.latitudeTarget.disabled = false;
        this.longitudeTarget.disabled = false;
        this.placeTarget.value = '';
        this.placeTarget.disabled = true;
        this.say('Recherche des producteurs autour de toi…');
        this.element.requestSubmit();

        // The form data is read when the submission starts: the field can come back right after.
        window.setTimeout(() => {
            this.placeTarget.disabled = false;
            this.buttonTarget.disabled = false;
        }, 0);
    }

    fail(error) {
        this.buttonTarget.disabled = false;

        if (error.code === error.PERMISSION_DENIED) {
            this.say('Ta position n’est pas partagée. Tape plutôt ta ville ou ton code postal.');
        } else if (error.code === error.TIMEOUT) {
            this.say('Ta position met trop de temps à arriver. Tape plutôt ta ville ou ton code postal.');
        } else {
            this.say('Impossible de trouver ta position. Tape plutôt ta ville ou ton code postal.');
        }

        this.placeTarget.focus();
    }

    say(message) {
        this.statusTarget.textContent = message;
        this.statusTarget.hidden = false;
    }
}
