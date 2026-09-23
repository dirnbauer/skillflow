/**
 * Skillflow run form: a skill run blocks the request until the model has
 * answered, which can take a while. While it runs, announce the progress to
 * assistive technology, mark the form busy and ignore further submits, so an
 * impatient second click never starts the same skill twice.
 *
 * The submit buttons stay enabled on purpose: a disabled submitter would drop
 * its name/value ("action=run") from the request.
 */
const forms = document.querySelectorAll('form[data-skillflow-run-form]');

forms.forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (form.getAttribute('aria-busy') === 'true') {
      event.preventDefault();
      return;
    }
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.setAttribute('aria-disabled', 'true');
    });
    const progress = form.querySelector('[data-skillflow-run-progress]');
    if (progress instanceof HTMLElement) {
      progress.hidden = false;
    }
  });
});

// Restored from the back/forward cache: the request has finished, so the form is usable again.
window.addEventListener('pageshow', (event) => {
  if (!event.persisted) {
    return;
  }
  forms.forEach((form) => {
    form.removeAttribute('aria-busy');
    form.querySelectorAll('button[type="submit"]').forEach((button) => button.removeAttribute('aria-disabled'));
    const progress = form.querySelector('[data-skillflow-run-progress]');
    if (progress instanceof HTMLElement) {
      progress.hidden = true;
    }
  });
});
