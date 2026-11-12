import * as Turbo from '@hotwired/turbo';

/*
 * Replaces the browser's confirm() of `data-turbo-confirm` with a <dialog> styled like the kit:
 * focus trapped by the native modal, Escape cancels, focus goes back to the submit button.
 *
 * Optional attributes on the form (or the submitter):
 *   data-confirm-title  — title of the dialog (default: the message itself)
 *   data-confirm-label  — label of the confirmation button (default: "Confirmer")
 *   data-confirm-cancel — label of the dismiss button (default: "Retour")
 * A form whose confirm button is destructive gets data-confirm-danger.
 */
const BUTTON = 'inline-flex h-11 items-center justify-center rounded-full border px-5 text-sm font-semibold whitespace-nowrap transition-colors';

function attribute(element, submitter, name) {
    return submitter?.getAttribute(name) ?? element.getAttribute(name);
}

function build() {
    const dialog = document.createElement('dialog');
    dialog.className = 'm-auto w-[calc(100%-2rem)] max-w-md rounded-xl bg-white p-6 text-ink shadow-card ring-1 ring-line backdrop:bg-ink/40';
    dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');
    dialog.setAttribute('aria-describedby', 'confirm-dialog-message');
    dialog.innerHTML = `
        <form method="dialog" class="flex flex-col gap-5">
            <div class="flex flex-col gap-2">
                <h2 id="confirm-dialog-title" class="text-lg leading-snug font-semibold text-ink"></h2>
                <p id="confirm-dialog-message" class="text-sm leading-relaxed text-ink-soft"></p>
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="submit" value="cancel" data-confirm-cancel class="${BUTTON} border-transparent text-sage-dark hover:bg-sage-soft"></button>
                <button type="submit" value="confirm" data-confirm-accept class="${BUTTON}"></button>
            </div>
        </form>`;
    document.body.append(dialog);

    return dialog;
}

Turbo.config.forms.confirm = (message, element, submitter) => new Promise((resolve) => {
    const dialog = build();
    const title = attribute(element, submitter, 'data-confirm-title');
    const danger = null !== attribute(element, submitter, 'data-confirm-danger');
    const accept = dialog.querySelector('[data-confirm-accept]');

    dialog.querySelector('h2').textContent = title || message;
    dialog.querySelector('p').textContent = title ? message : '';
    dialog.querySelector('p').hidden = !title;
    dialog.querySelector('[data-confirm-cancel]').textContent = attribute(element, submitter, 'data-confirm-cancel') || 'Retour';
    accept.textContent = attribute(element, submitter, 'data-confirm-label') || 'Confirmer';
    accept.classList.add(...(danger
        ? ['border-danger/25', 'bg-danger-soft', 'text-danger', 'hover:bg-danger/15']
        : ['border-transparent', 'bg-wheat', 'text-ink', 'hover:bg-wheat-dark']));

    dialog.addEventListener('close', () => {
        resolve('confirm' === dialog.returnValue);
        dialog.remove();
        submitter?.focus();
    }, { once: true });

    dialog.showModal();
    // The safe choice has the focus.
    dialog.querySelector('[data-confirm-cancel]').focus();
});
