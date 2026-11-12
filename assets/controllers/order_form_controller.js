import { Controller } from '@hotwired/stimulus';

/*
 * Order form: quantity steppers, live item count and total, home-delivery address.
 * The form still works without JavaScript (plain number inputs, total computed by the server).
 *
 * Quantities are handled in thousandths of the unit, like on the server: a product sold by weight
 * (data-weighed) moves by its step (data-step, e.g. 250 = 0,25 kg) from its minimum (data-minimum),
 * counts as one item, and makes the total an estimate ("environ", target `estimate`). Line totals
 * are rounded to the cent, halves up (App\Shared\Service\Quantity::priceCents()).
 *
 * <form data-controller="order-form">
 *   <input type="radio" data-order-form-target="delivery" data-home="true" data-action="order-form#toggleAddress">
 *   <div data-order-form-target="address" hidden><textarea data-order-form-target="addressInput"></textarea></div>
 *   <div data-order-form-row>
 *     <button type="button" data-action="order-form#decrement" data-order-form-target="minus">−</button>
 *     <input type="number" data-order-form-target="quantity" data-price-cents="520" data-action="input->order-form#refresh">
 *     <button type="button" data-action="order-form#increment" data-order-form-target="plus">+</button>
 *   </div>
 *   <span data-order-form-target="count"></span> <span data-order-form-target="total"></span>
 * </form>
 */
export default class extends Controller {
    static targets = ['quantity', 'count', 'total', 'estimate', 'delivery', 'address', 'addressInput'];

    connect() {
        this.money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
        this.refresh();
        this.toggleAddress();
    }

    increment(event) {
        this.step(event, 1);
    }

    decrement(event) {
        this.step(event, -1);
    }

    step(event, direction) {
        const row = event.currentTarget.closest('[data-order-form-row]');
        const input = row ? row.querySelector('[data-order-form-target~="quantity"]') : null;
        if (!input) {
            return;
        }

        const current = this.valueOf(input);
        const minimum = this.minimumOf(input);
        let next = current + direction * this.stepOf(input);
        if (direction > 0 && next < minimum) {
            next = minimum;
        } else if (direction < 0 && next < minimum) {
            next = 0;
        }

        input.value = this.format(this.clamp(input, next));
        this.refresh();
    }

    refresh() {
        let count = 0;
        let total = 0;
        let estimated = false;

        this.quantityTargets.forEach((input) => {
            const quantity = this.clamp(input, this.valueOf(input));
            const weighed = undefined !== input.dataset.weighed && 'false' !== input.dataset.weighed;
            if (quantity > 0) {
                count += weighed ? 1 : Math.floor(quantity / 1000);
                estimated = estimated || weighed;
            }
            total += this.price(quantity, parseInt(input.dataset.priceCents, 10) || 0);

            const row = input.closest('[data-order-form-row]');
            if (row) {
                row.toggleAttribute('data-selected', quantity > 0);
                const minus = row.querySelector('[data-action~="order-form#decrement"]');
                const plus = row.querySelector('[data-action~="order-form#increment"]');
                if (minus) {
                    minus.disabled = quantity <= 0;
                }
                if (plus) {
                    plus.disabled = Math.max(quantity + this.stepOf(input), this.minimumOf(input)) > this.maxOf(input);
                }
            }
        });

        this.countTargets.forEach((element) => {
            element.textContent = 0 === count ? 'Panier vide' : `${count} article${count > 1 ? 's' : ''}`;
        });
        this.totalTargets.forEach((element) => {
            element.textContent = this.money.format(total / 100);
        });
        this.estimateTargets.forEach((element) => {
            element.hidden = !estimated;
        });
    }

    toggleAddress() {
        if (!this.hasAddressTarget) {
            return;
        }

        const checked = this.deliveryTargets.find((input) => input.checked);
        const home = Boolean(checked) && 'true' === checked.dataset.home;

        this.addressTarget.hidden = !home;
        if (this.hasAddressInputTarget) {
            this.addressInputTarget.required = home;
        }
    }

    // Thousandths of the unit typed in the input ("1.5" or "1,5" → 1500).
    valueOf(input) {
        return this.thousandths(input.value);
    }

    thousandths(value) {
        const number = parseFloat(String(value).replace(',', '.'));

        return Number.isNaN(number) || number < 0 ? 0 : Math.round(number * 1000);
    }

    format(quantity) {
        return String(quantity / 1000);
    }

    stepOf(input) {
        return parseInt(input.dataset.step, 10) || 1000;
    }

    minimumOf(input) {
        return parseInt(input.dataset.minimum, 10) || this.stepOf(input);
    }

    maxOf(input) {
        const max = this.thousandths(input.max);

        return '' === input.max ? 99000 : max;
    }

    clamp(input, value) {
        const step = this.stepOf(input);
        const max = this.maxOf(input) - (this.maxOf(input) % step);

        return Math.min(max, Math.max(0, value));
    }

    // Same rounding as the server: to the nearest cent, halves up.
    price(quantity, unitPriceCents) {
        return Math.floor((2 * quantity * unitPriceCents + 1000) / 2000);
    }
}
