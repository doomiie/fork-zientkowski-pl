(function () {
    const STYLE_ID = 'doc-popup-counter-style';
    const DEFAULT_ENDPOINT = '/backend/docs.php';
    const VISIBLE_CLASS = 'doc-popup-counter--visible';
    const cache = {};
    let uid = 0;

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = `
.doc-popup-counter {
  position: fixed;
  left: 16px;
  bottom: 16px;
  max-width: 340px;
  padding: 14px 16px;
  border-radius: 14px;
  background: #0f172a;
  color: #e5e7eb;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.08);
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 12px;
  align-items: start;
  font-size: 14px;
  line-height: 1.45;
  z-index: 1300;
  opacity: 0;
  transform: translateY(12px);
  transition: opacity 0.25s ease, transform 0.25s ease;
}
.doc-popup-counter--visible {
  opacity: 1;
  transform: translateY(0);
}
.doc-popup-counter__badge {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: linear-gradient(135deg, #f59e0b, #ea580c);
  color: #0f172a;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  letter-spacing: 0.3px;
  box-shadow: 0 10px 25px rgba(234, 88, 12, 0.25);
}
.doc-popup-counter__text {
  margin: 0;
}
.doc-popup-counter__close {
  background: transparent;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  font-size: 16px;
  padding: 2px;
  line-height: 1;
  transition: color 0.15s ease, transform 0.15s ease;
}
.doc-popup-counter__close:hover {
  color: #f9fafb;
  transform: scale(1.05);
}
@media (max-width: 640px) {
  .doc-popup-counter {
    left: 12px;
    right: 12px;
    max-width: none;
    bottom: 12px;
  }
}
        `;
        document.head.appendChild(style);
    }

    function formatTemplate(template, data) {
        return (template || '').replace(/{([^}]+)}/g, function (match, key) {
            const value = Object.prototype.hasOwnProperty.call(data, key) ? data[key] : '';
            return value === undefined || value === null ? '' : String(value);
        });
    }

    function normalizeTrigger(trigger) {
        const defaults = { type: 'time', delaySeconds: 5, durationSeconds: 10, scrollThreshold: 16 };
        if (!trigger) {
            return defaults;
        }
        if (Array.isArray(trigger) && trigger.length) {
            return {
                type: trigger[0] || defaults.type,
                delaySeconds: Number(trigger[1]) || defaults.delaySeconds,
                durationSeconds: Number(trigger[2]) || defaults.durationSeconds,
                scrollThreshold: trigger[3] !== undefined ? Number(trigger[3]) : defaults.scrollThreshold
            };
        }
        if (typeof trigger === 'string') {
            return { ...defaults, type: trigger };
        }
        if (typeof trigger === 'object') {
            return {
                type: trigger.type || defaults.type,
                delaySeconds: Number(trigger.delaySeconds ?? trigger.delay ?? defaults.delaySeconds) || defaults.delaySeconds,
                durationSeconds: Number(trigger.durationSeconds ?? trigger.duration ?? defaults.durationSeconds) || defaults.durationSeconds,
                scrollThreshold: trigger.scrollThreshold !== undefined ? Number(trigger.scrollThreshold) : defaults.scrollThreshold
            };
        }
        return defaults;
    }

    function normalizeDoc(doc, params) {
        const base = {
            display_name: '',
            download_count: '',
            last_download_date: ''
        };
        if (!doc) {
            return { ...base, ...(params || {}) };
        }
        const normalized = { ...doc };
        normalized.display_name = (doc.display_name || doc.original_name || '').trim();
        normalized.download_count = typeof doc.download_count === 'number'
            ? doc.download_count.toLocaleString('pl-PL')
            : (doc.download_count || '');
        normalized.last_download_date = doc.last_download_date || doc.last_download_at || '';
        return { ...base, ...(params || {}), ...normalized };
    }

    function selectDoc(items, criteria) {
        if (!Array.isArray(items) || !items.length) return null;
        if (!criteria || typeof criteria !== 'object') return items[0];
        if (criteria.id) {
            const match = items.find(item => Number(item.id) === Number(criteria.id));
            if (match) return match;
        }
        if (criteria.shareHash) {
            const match = items.find(item => typeof item.share_url === 'string' && item.share_url.indexOf(criteria.shareHash) !== -1);
            if (match) return match;
        }
        if (criteria.shareUrl) {
            const match = items.find(item => item.share_url === criteria.shareUrl);
            if (match) return match;
        }
        if (criteria.displayName) {
            const name = String(criteria.displayName).toLowerCase();
            const match = items.find(item => String(item.display_name || '').toLowerCase().indexOf(name) !== -1);
            if (match) return match;
        }
        const idx = typeof criteria.index === 'number' ? criteria.index : 0;
        return items[Math.max(0, Math.min(items.length - 1, idx))];
    }

    async function fetchDocs(endpoint) {
        const url = endpoint || DEFAULT_ENDPOINT;
        if (cache[url]) return cache[url];
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) {
                throw new Error('Failed to fetch docs: ' + res.status);
            }
            const data = await res.json();
            cache[url] = Array.isArray(data.items) ? data.items : [];
            return cache[url];
        } catch (err) {
            console.warn('[popup-counter] Could not load docs', err);
            cache[url] = [];
            return cache[url];
        }
    }

    function createPopupShell() {
        ensureStyles();
        const el = document.createElement('div');
        el.className = 'doc-popup-counter';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');

        const badge = document.createElement('div');
        badge.className = 'doc-popup-counter__badge';
        badge.textContent = 'DOC';

        const text = document.createElement('p');
        text.className = 'doc-popup-counter__text';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'doc-popup-counter__close';
        close.setAttribute('aria-label', 'Zamknij powiadomienie');
        close.textContent = 'x';

        el.appendChild(badge);
        el.appendChild(text);
        el.appendChild(close);
        document.body.appendChild(el);

        return { el, text, close };
    }

    function schedule(trigger, showFn) {
        const durationMs = Math.max(0, (trigger.durationSeconds || 0) * 1000);
        if (trigger.type === 'scroll') {
            const threshold = Math.max(0, trigger.scrollThreshold || 0);
            const handler = function () {
                if (window.scrollY >= threshold) {
                    window.removeEventListener('scroll', handler);
                    showFn(durationMs);
                }
            };
            window.addEventListener('scroll', handler, { passive: true });
            if (window.scrollY >= threshold) {
                handler();
            }
            return;
        }
        const delay = Math.max(0, (trigger.delaySeconds || 0) * 1000);
        setTimeout(function () { showFn(durationMs); }, delay);
    }

    async function DisplayPopupCounter(options) {
        const opts = options || {};
        const trigger = normalizeTrigger(opts.trigger);
        const endpoint = opts.endpoint || DEFAULT_ENDPOINT;
        const docs = await fetchDocs(endpoint);
        const doc = selectDoc(docs, opts.doc || opts.docMatch || opts);
        const params = normalizeDoc(doc, opts.params);
        const template = opts.template || opts.string || 'Plik {display_name} pobrano {download_count} razy.';
        const message = formatTemplate(template, params).trim();
        if (!message) {
            return;
        }

        const instanceId = ++uid;
        const popup = createPopupShell();
        popup.el.dataset.popupId = String(instanceId);
        popup.text.textContent = message;

        const hide = function () {
            popup.el.classList.remove(VISIBLE_CLASS);
            setTimeout(function () {
                if (popup.el && popup.el.parentNode) {
                    popup.el.parentNode.removeChild(popup.el);
                }
            }, 250);
        };

        popup.close.addEventListener('click', hide);

        schedule(trigger, function (durationMs) {
            popup.el.classList.add(VISIBLE_CLASS);
            if (durationMs > 0) {
                setTimeout(hide, durationMs);
            }
        });
    }

    window.DisplayPopupCounter = DisplayPopupCounter;
})();
