import { Controller } from '@hotwired/stimulus';

/*
 * Live preview of the next sale of a recurrence (sale/schedule/form.html.twig), with the same rules
 * as SaleSchedule / SaleGenerator: next delivery day strictly after today, skipped when its deadline
 * is already past; orders open N days before at 8 h.
 */
const NBSP = ' ';
const OPENING_HOUR = 8;
const dateFormat = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });

export default class extends Controller {
    static targets = ['weekday', 'start', 'end', 'opens', 'deadlineDays', 'deadlineTime', 'output'];

    connect() {
        this.refresh();
    }

    refresh() {
        const weekday = parseInt(this.weekdayTarget.value, 10);
        const start = this._time(this.startTarget.value);
        const end = this._time(this.endTarget.value);
        const deadlineTime = this._time(this.deadlineTimeTarget.value);
        const opensDays = parseInt(this.opensTarget.value, 10);
        const deadlineDays = parseInt(this.deadlineDaysTarget.value, 10);

        if (!(weekday >= 1 && weekday <= 7) || !start || !end || !deadlineTime || !(opensDays >= 0) || !(deadlineDays >= 0)) {
            this.outputTarget.hidden = true;

            return;
        }

        const now = new Date();
        let delivery = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        let deadline;
        for (let i = 0; i < 21; i++) {
            delivery = this._addDays(delivery, 1);
            if (this._isoWeekday(delivery) !== weekday) {
                continue;
            }
            deadline = this._at(this._addDays(delivery, -deadlineDays), deadlineTime);
            if (deadline > now) {
                break;
            }
        }

        const opens = this._at(this._addDays(delivery, -opensDays), [OPENING_HOUR, 0]);

        this.outputTarget.textContent = `Prochaine vente : livraison ${dateFormat.format(delivery)}, ${this._formatTime(start)} – ${this._formatTime(end)}. `
            + `Commandes ouvertes du ${this._formatDateTime(opens)} au ${this._formatDateTime(deadline)}.`;
        this.outputTarget.hidden = false;
    }

    _time(value) {
        const match = /^(\d{1,2}):(\d{2})/.exec(value || '');

        return match ? [parseInt(match[1], 10), parseInt(match[2], 10)] : null;
    }

    _addDays(date, days) {
        const copy = new Date(date);
        copy.setDate(copy.getDate() + days);

        return copy;
    }

    _at(day, [hours, minutes]) {
        const copy = new Date(day);
        copy.setHours(hours, minutes, 0, 0);

        return copy;
    }

    _isoWeekday(date) {
        return 0 === date.getDay() ? 7 : date.getDay();
    }

    _formatTime([hours, minutes]) {
        return `${hours}${NBSP}h${minutes ? NBSP + String(minutes).padStart(2, '0') : ''}`;
    }

    _formatDateTime(date) {
        return `${dateFormat.format(date)} à ${this._formatTime([date.getHours(), date.getMinutes()])}`;
    }
}
