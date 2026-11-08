/**
 * Skill detail page, progressive enhancement. Without JavaScript both
 * install ways (Command, Prompt) are listed under their headings, SKILL.md
 * is shown in full and the commands are selectable text. With it:
 * - "Command" and "Prompt" become tabs (WAI-ARIA tabs, ←/→/Home/End);
 * - copy buttons appear where the Clipboard API works;
 * - the agent switch rewrites the skills folder in the commands and is
 *   remembered (shared with the skills search);
 * - a long SKILL.md is cut to a readable height with "Show more".
 */
(() => {
  const page = document.querySelector('[data-skillflow-skill]');
  if (!page) {
    return;
  }

  const announcer = document.createElement('p');
  announcer.className = 'sf-sr-only';
  announcer.setAttribute('aria-live', 'polite');
  page.append(announcer);

  // ----- Tabs ----------------------------------------------------------------
  page.querySelectorAll('[data-skillflow-tabs]').forEach((section) => {
    const tablist = section.querySelector('[data-skillflow-tablist]');
    const tabs = Array.from(section.querySelectorAll('[data-skillflow-tab]'));
    const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls') || ''));
    if (!tablist || tabs.length === 0 || panels.some((panel) => !panel)) {
      return;
    }
    const select = (index, focus) => {
      tabs.forEach((tab, i) => {
        const active = i === index;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        panels[i].hidden = !active;
      });
      if (focus) {
        tabs[index].focus();
      }
    };
    tablist.hidden = false;
    panels.forEach((panel, i) => {
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', tabs[i].id);
      panel.tabIndex = 0;
      panel.querySelector('[data-skillflow-tabpanel-title]')?.classList.add('sf-sr-only');
    });
    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => select(i, false));
      tab.addEventListener('keydown', (event) => {
        const last = tabs.length - 1;
        const next = { ArrowRight: i === last ? 0 : i + 1, ArrowLeft: i === 0 ? last : i - 1, Home: 0, End: last }[event.key];
        if (next !== undefined) {
          event.preventDefault();
          select(next, true);
        }
      });
    });
    select(0, false);
  });

  // ----- Copy ------------------------------------------------------------------
  if (navigator.clipboard && window.isSecureContext) {
    page.querySelectorAll('[data-skillflow-copy]').forEach((button) => {
      button.hidden = false;
    });
    page.addEventListener('click', async (event) => {
      const button = event.target.closest?.('[data-skillflow-copy]');
      const source = button && document.getElementById(button.dataset.skillflowCopy || '');
      if (!source) {
        return;
      }
      try {
        await navigator.clipboard.writeText(source.textContent.trim());
        const label = button.querySelector('.sf-copy__label');
        const original = label?.textContent || '';
        button.classList.add('sf-copy--done');
        if (label) {
          label.textContent = button.dataset.skillflowCopied || original;
        }
        announcer.textContent = button.dataset.skillflowCopied || '';
        window.setTimeout(() => {
          button.classList.remove('sf-copy--done');
          if (label) {
            label.textContent = original;
          }
          announcer.textContent = '';
        }, 2000);
      } catch {
        // No permission: the text stays selectable.
      }
    });
  }

  // ----- Agent switch ------------------------------------------------------------
  const AGENT_KEY = 'skillflow.agentDirectory';
  const applyAgent = (directory) => {
    page.querySelectorAll('[data-skillflow-agent-select]').forEach((select) => {
      const fallback = select.dataset.skillflowAgentDefault || '';
      const target = directory && Array.from(select.options).some((option) => option.value === directory) ? directory : fallback;
      select.value = target;
      page.querySelectorAll('[data-skillflow-command]').forEach((code) => {
        if (code.dataset.skillflowOriginal === undefined) {
          code.dataset.skillflowOriginal = code.textContent;
        }
        code.textContent = code.dataset.skillflowOriginal.split(fallback).join(target);
      });
    });
  };
  page.querySelectorAll('[data-skillflow-agent]').forEach((label) => {
    label.hidden = false;
  });
  page.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-skillflow-agent-select]');
    if (!select) {
      return;
    }
    try {
      window.localStorage.setItem(AGENT_KEY, select.value);
    } catch {
      // Private mode: the choice lasts for this page.
    }
    applyAgent(select.value);
  });
  let stored = '';
  try {
    stored = window.localStorage.getItem(AGENT_KEY) || '';
  } catch {
    stored = '';
  }
  applyAgent(stored);

  // ----- Show more -----------------------------------------------------------------
  page.querySelectorAll('[data-skillflow-collapsible]').forEach((doc) => {
    // Only cut what is clearly longer than the cut itself.
    if (doc.scrollHeight < 1400) {
      return;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sf-doc__toggle';
    button.setAttribute('aria-controls', doc.id);
    button.setAttribute('aria-expanded', 'false');
    button.textContent = doc.dataset.skillflowMore || 'Show more';
    doc.classList.add('sf-doc--collapsed');
    doc.after(button);
    button.addEventListener('click', () => {
      const expand = doc.classList.contains('sf-doc--collapsed');
      doc.classList.toggle('sf-doc--collapsed', !expand);
      button.setAttribute('aria-expanded', String(expand));
      button.textContent = expand ? (doc.dataset.skillflowLess || 'Show less') : (doc.dataset.skillflowMore || 'Show more');
      if (!expand) {
        doc.closest('section')?.scrollIntoView({ block: 'start' });
      }
    });
    // A link to a heading inside the cut part opens it first.
    const openFor = () => {
      const target = window.location.hash && doc.querySelector(window.location.hash.replace(/[^#\w-]/g, ''));
      if (target && doc.classList.contains('sf-doc--collapsed')) {
        button.click();
        target.scrollIntoView();
      }
    };
    window.addEventListener('hashchange', openFor);
    openFor();
  });
})();
