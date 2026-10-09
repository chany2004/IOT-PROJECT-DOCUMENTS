(() => {
    'use strict';
    const form = document.querySelector('[data-review-response]');
    if (!form) return;
    const note = form.querySelector('[name="note"]');
    const count = form.querySelector('[data-review-note-count]');
    const button = form.querySelector('[type="submit"]');
    const updateCount = () => {
        if (note && count) count.textContent = `${Array.from(note.value).length.toLocaleString('en-US')} / 4,000`;
    };
    note?.addEventListener('input', updateCount);
    updateCount();
    form.addEventListener('submit', event => {
        if (form.dataset.saving === 'true') { event.preventDefault(); return; }
        if (!button || !form.checkValidity()) return;
        form.dataset.saving = 'true';
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        button.classList.add('is-saving');
        button.querySelector('span').textContent = 'Saving response…';
    });
    window.addEventListener('pageshow', () => {
        if (!button) return;
        delete form.dataset.saving;
        form.removeAttribute('aria-busy');
        button.disabled = false;
        button.classList.remove('is-saving');
        button.querySelector('span').textContent = 'Save caregiver response';
    });
})();
