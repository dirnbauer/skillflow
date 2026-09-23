/**
 * Skill detail: a "Copy" button for the skill ID. The button ships hidden and
 * only appears where the Clipboard API is available (secure contexts), so
 * visitors without JavaScript simply select the ID text.
 */
(() => {
  if (!navigator.clipboard || !window.isSecureContext) {
    return;
  }
  document.querySelectorAll('[data-skillflow-copy]').forEach((button) => {
    const source = document.getElementById(button.dataset.skillflowCopy || '');
    const status = button.closest('section')?.querySelector('[data-skillflow-copy-status]');
    if (!source) {
      return;
    }
    button.hidden = false;
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(source.textContent.trim());
        if (status) {
          status.textContent = button.dataset.skillflowCopied || '';
          window.setTimeout(() => { status.textContent = ''; }, 3000);
        }
      } catch {
        // Permission denied: the ID stays selectable text.
      }
    });
  });
})();
