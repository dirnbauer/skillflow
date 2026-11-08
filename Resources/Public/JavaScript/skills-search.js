/**
 * Skillflow skills search: results while typing, keyboard first.
 *
 * Progressive enhancement of the Solr results page: without this script the
 * form submits and every topic, filter and sort is a plain link. With it:
 * - "/" focuses the field, typing anywhere on the page types into it,
 *   Esc clears it (a second Esc leaves the field);
 * - from two characters on, results load while typing (debounced, the
 *   previous request is cancelled) and replace the list in place; the query
 *   lives in the URL (?tx_solr[q]=…), so back and forward work;
 * - ↓ in the field moves to the first result, ↑/↓ move through the results,
 *   Enter opens one (it is a link);
 * - topics, filters, sorting and paging load in place, too;
 * - the count line is a polite live region, so screen readers hear it;
 * - copy buttons for the install commands, and an agent switch that writes
 *   the commands for another agent's skills folder.
 * The server renders every state; this script only fetches and swaps it.
 */
(() => {
  const root = document.querySelector('[data-skillflow-search]');
  if (!root || !window.fetch || !window.DOMParser) {
    return;
  }
  const input = root.querySelector('[data-skillflow-search-input]');
  const form = root.querySelector('[data-skillflow-search-form]');
  const live = root.querySelector('[data-skillflow-search-live]');
  if (!input || !form || !live) {
    return;
  }

  const minChars = Number.parseInt(root.dataset.skillflowMinChars || '2', 10);
  const queryParam = input.name;
  const pageParam = queryParam.replace(/\[q\]$/, '[page]');
  const announcer = document.createElement('p');
  announcer.className = 'sf-sr-only';
  announcer.setAttribute('aria-live', 'polite');
  root.append(announcer);

  let controller = null;
  let timer = 0;
  let lastQuery = input.value.trim();

  root.classList.add('sf-search--live');

  const region = () => root.querySelector('[data-skillflow-search-results]');
  const resultLinks = () => Array.from(root.querySelectorAll('[data-skillflow-result-link]'));
  const isEditable = (element) => element instanceof HTMLElement
    && (element.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(element.tagName));

  /** The URL of a search for `query`, keeping topic, filters and sorting. */
  const urlFor = (query) => {
    const url = new URL(window.location.href);
    url.searchParams.delete(pageParam);
    if (query === '') {
      url.searchParams.delete(queryParam);
    } else {
      url.searchParams.set(queryParam, query);
    }
    url.hash = '';
    return url;
  };

  const restoreAfterSwap = (previousKey, openFilter) => {
    enhance(region());
    if (openFilter) {
      const details = root.querySelector(`[data-skillflow-filter="${CSS.escape(openFilter)}"]`);
      if (details) {
        details.open = true;
      }
    }
    if (previousKey) {
      // Focus stays on the same topic, filter option or sort; when that is
      // gone (a removed filter), on the count line.
      const counterpart = root.querySelector(`[data-skillflow-key="${CSS.escape(previousKey)}"]`);
      const status = root.querySelector('[data-skillflow-search-status]');
      if (status) {
        status.tabIndex = -1;
      }
      (counterpart || status)?.focus({ preventScroll: true });
    }
  };

  /**
   * Loads `url` and swaps the results region and the status line.
   * history: 'push', 'replace' or '' (popstate: the URL is already there).
   */
  const load = async (url, history, focusKey = '', openFilter = '') => {
    controller?.abort();
    controller = new AbortController();
    const current = region();
    current?.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {
        signal: controller.signal,
        headers: { Accept: 'text/html' },
        credentials: 'same-origin',
      });
      if (!response.ok) {
        throw new Error(String(response.status));
      }
      const html = await response.text();
      const next = new DOMParser().parseFromString(html, 'text/html');
      const nextRegion = next.querySelector('[data-skillflow-search-results]');
      const nextStatus = next.querySelector('[data-skillflow-search-status]');
      if (!nextRegion || !current) {
        throw new Error('no results region');
      }
      current.replaceWith(document.importNode(nextRegion, true));
      // The count line was swapped with the results; the persistent live
      // region tells screen readers about it.
      live.textContent = nextStatus ? nextStatus.textContent.replace(/\s+/g, ' ').trim() : '';
      if (history === 'push') {
        window.history.pushState({ skillflow: true }, '', url);
      } else if (history === 'replace') {
        window.history.replaceState({ skillflow: true }, '', url);
      }
      restoreAfterSwap(focusKey, openFilter);
    } catch (error) {
      if (error.name !== 'AbortError') {
        // The plain page still works: go there.
        window.location.assign(url);
      }
    } finally {
      region()?.setAttribute('aria-busy', 'false');
    }
  };

  const search = (immediate = false) => {
    window.clearTimeout(timer);
    const query = input.value.trim();
    if (query === lastQuery || (query.length > 0 && query.length < minChars)) {
      return;
    }
    // skills.sh's rhythm: short queries wait longer, they change the most.
    const delay = immediate ? 0 : Math.max(150, 350 - 50 * query.length);
    timer = window.setTimeout(() => {
      // The first character of a new search is a history entry; the rest
      // of the typing replaces it.
      const history = lastQuery === '' && query !== '' ? 'push' : 'replace';
      lastQuery = query;
      load(urlFor(query), history);
    }, delay);
  };

  input.addEventListener('input', () => search());
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    lastQuery = lastQuery === input.value.trim() ? '\u0000' : lastQuery;
    search(true);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      if (input.value !== '') {
        event.preventDefault();
        input.value = '';
        search(true);
      } else {
        input.blur();
      }
    } else if (event.key === 'ArrowDown') {
      const first = resultLinks()[0];
      if (first) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  // "/" and type-anywhere. Space keeps scrolling the page.
  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || isEditable(event.target)) {
      return;
    }
    if (event.key === '/') {
      event.preventDefault();
      input.focus();
      input.select();
      return;
    }
    if (event.key.length === 1 && event.key !== ' ' && !event.target.closest?.('details[open]')) {
      // Focus before the key's input event: the character lands in the field.
      input.focus();
      const end = input.value.length;
      input.setSelectionRange(end, end);
    }
  });

  // ↑/↓ through the results, Home/End to the ends, ↑ on the first back to the field.
  root.addEventListener('keydown', (event) => {
    const link = event.target.closest?.('[data-skillflow-result-link]');
    if (!link || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      return;
    }
    const links = resultLinks();
    const index = links.indexOf(link);
    let target = null;
    if (event.key === 'ArrowDown') {
      target = links[index + 1] || null;
    } else if (event.key === 'ArrowUp') {
      target = index > 0 ? links[index - 1] : input;
    } else if (event.key === 'Home') {
      target = links[0];
    } else {
      target = links[links.length - 1];
    }
    if (target) {
      event.preventDefault();
      target.focus();
      if (target !== input) {
        target.closest('[data-skillflow-result]')?.scrollIntoView({ block: 'nearest' });
      }
    }
  });

  // Topics, filters, sorting and paging load in place.
  root.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[data-skillflow-nav]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
      return;
    }
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) {
      return;
    }
    event.preventDefault();
    const filter = link.closest('[data-skillflow-filter]');
    const keepOpen = filter && filter.dataset.skillflowFilter !== 'sort' && root.classList.contains('sf-search--site')
      ? filter.dataset.skillflowFilter
      : '';
    const query = url.searchParams.get(queryParam) || '';
    input.value = query;
    lastQuery = query;
    load(url, 'push', link.dataset.skillflowKey || '', keepOpen);
  });

  window.addEventListener('popstate', () => {
    const query = new URL(window.location.href).searchParams.get(queryParam) || '';
    input.value = query;
    lastQuery = query;
    load(new URL(window.location.href), '');
  });

  // One filter panel open at a time; Esc or a click outside closes it.
  document.addEventListener('click', (event) => {
    if (!root.classList.contains('sf-search--site')) {
      return;
    }
    root.querySelectorAll('details[data-skillflow-filter][open]').forEach((details) => {
      if (!details.contains(event.target)) {
        details.open = false;
      }
    });
  });
  root.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !root.classList.contains('sf-search--site')) {
      return;
    }
    const details = event.target.closest?.('details[data-skillflow-filter][open]');
    if (details) {
      details.open = false;
      details.querySelector('summary')?.focus();
    }
  });
  root.addEventListener('toggle', (event) => {
    const details = event.target;
    if (details.open && details.matches?.('[data-skillflow-filter]') && root.classList.contains('sf-search--site')) {
      root.querySelectorAll('details[data-skillflow-filter][open]').forEach((other) => {
        if (other !== details) {
          other.open = false;
        }
      });
    }
  }, true);

  // ----- Commands: copy, and the agent they are written for -------------

  const AGENT_KEY = 'skillflow.agentDirectory';

  const applyAgent = (scope, directory) => {
    scope.querySelectorAll('[data-skillflow-agent-select]').forEach((select) => {
      const fallback = select.dataset.skillflowAgentDefault || '';
      const target = directory && Array.from(select.options).some((option) => option.value === directory) ? directory : fallback;
      select.value = target;
      scope.querySelectorAll('[data-skillflow-command]').forEach((code) => {
        if (code.dataset.skillflowOriginal === undefined) {
          code.dataset.skillflowOriginal = code.textContent;
        }
        code.textContent = code.dataset.skillflowOriginal.split(fallback).join(target);
      });
    });
  };

  const narrow = window.matchMedia('(max-width: 61.99rem)');

  const enhance = (scope) => {
    if (!scope) {
      return;
    }
    // The typo3.org look lists its filters open in the sidebar; on a small
    // screen that sidebar comes first, so its filters start closed there.
    if (root.classList.contains('sf-search--typo3') && narrow.matches) {
      scope.querySelectorAll('details[data-skillflow-filter]:not([data-skillflow-filter="sort"])').forEach((details) => {
        details.open = false;
      });
    }
    if (navigator.clipboard && window.isSecureContext) {
      scope.querySelectorAll('[data-skillflow-copy]').forEach((button) => {
        button.hidden = false;
      });
    }
    scope.querySelectorAll('[data-skillflow-agent]').forEach((label) => {
      label.hidden = false;
    });
    let stored = '';
    try {
      stored = window.localStorage.getItem(AGENT_KEY) || '';
    } catch {
      stored = '';
    }
    applyAgent(scope, stored);
  };

  root.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-skillflow-agent-select]');
    if (!select) {
      return;
    }
    try {
      window.localStorage.setItem(AGENT_KEY, select.value);
    } catch {
      // Private mode: the choice lasts for this page.
    }
    applyAgent(root, select.value);
  });

  root.addEventListener('click', async (event) => {
    const button = event.target.closest?.('[data-skillflow-copy]');
    if (!button) {
      return;
    }
    const source = document.getElementById(button.dataset.skillflowCopy || '');
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
      // No permission: the command stays selectable text.
    }
  });

  enhance(root);
})();
