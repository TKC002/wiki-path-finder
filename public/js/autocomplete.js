/* public/js/autocomplete.js */

class Autocomplete {
    constructor(opts) {
        this.wrap = opts.wrap;
        this.input = opts.input;
        this.list = opts.list;
        this.chipBox = opts.chip;
        this.hidden = opts.hidden;
        this.suggestUrl = opts.suggestUrl;
        this.items = [];
        this.activeIdx = -1;
        this.timer = null;
        this.lastQuery = '';
        this.aborter = null;

        this.input.addEventListener('input', () => this.onInput());
        this.input.addEventListener('focus', () => this.onInput());
        this.input.addEventListener('keydown', (e) => this.onKeydown(e));
        this.input.addEventListener('blur', () => {
            setTimeout(() => this.hide(), 150);
        });
        this.input.addEventListener('change', () => this.maybePromoteUrlToChip());
        this.input.addEventListener('paste', () => {
            setTimeout(() => this.maybePromoteUrlToChip(), 0);
        });
    }

    onInput() {
        const q = this.input.value.trim();
        if (/^https?:\/\//i.test(q) || q === '') {
            this.hide();
            return;
        }
        if (q === this.lastQuery) return;
        this.lastQuery = q;

        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetch(q), 220);
    }

    async fetch(q) {
        if (this.aborter) this.aborter.abort();
        this.aborter = new AbortController();

        this.show();
        this.list.innerHTML = '<div class="suggest-loading">検索中…</div>';

        try {
            const res = await fetch(
                this.suggestUrl + '?q=' + encodeURIComponent(q) + '&lang=ja',
                { signal: this.aborter.signal }
            );
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (this.input.value.trim() !== q) return;
            this.render(data.suggestions || [], q);
        } catch (e) {
            if (e.name === 'AbortError') return;
            this.list.innerHTML = '<div class="suggest-empty">候補を取得できませんでした</div>';
        }
    }

    render(items, q) {
        this.items = items;
        this.activeIdx = -1;
        if (items.length === 0) {
            this.list.innerHTML = '<div class="suggest-empty">候補が見つかりません</div>';
            return;
        }
        const re = new RegExp('(' + escapeRegex(q) + ')', 'ig');
        this.list.innerHTML = items.map((it, i) => {
            const safe = escapeHtml(it.title);
            const highlighted = safe.replace(re, '<span class="match">$1</span>');
            return `<div class="suggest-item" data-idx="${i}">${highlighted}</div>`;
        }).join('');

        this.list.querySelectorAll('.suggest-item').forEach((el) => {
            el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.choose(parseInt(el.dataset.idx, 10));
            });
        });
    }

    onKeydown(e) {
        if (this.list.hidden || this.items.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.move(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.move(-1);
        } else if (e.key === 'Enter') {
            if (this.activeIdx >= 0) {
                e.preventDefault();
                this.choose(this.activeIdx);
            }
        } else if (e.key === 'Escape') {
            this.hide();
        }
    }

    move(delta) {
        const els = this.list.querySelectorAll('.suggest-item');
        if (els.length === 0) return;
        if (this.activeIdx >= 0) els[this.activeIdx].classList.remove('active');
        this.activeIdx = (this.activeIdx + delta + els.length) % els.length;
        els[this.activeIdx].classList.add('active');
        els[this.activeIdx].scrollIntoView({ block: 'nearest' });
    }

    choose(idx) {
        const item = this.items[idx];
        if (!item) return;
        this.setChip(item.title, item.url);
        this.hide();
    }

    maybePromoteUrlToChip() {
        const v = this.input.value.trim();
        const m = v.match(/^https?:\/\/[a-z-]+\.wikipedia\.org\/wiki\/([^?#\s]+)/i);
        if (!m) return;
        let title;
        try {
            title = decodeURIComponent(m[1]).replace(/_/g, ' ');
        } catch (_) {
            title = m[1].replace(/_/g, ' ');
        }
        this.setChip(title, v);
    }

    setChip(title, url) {
        this.hidden.value = url;
        this.input.value = '';
        this.lastQuery = '';
        this.chipBox.innerHTML = `
            <span class="chip">
                <span class="chip-title" title="${escapeHtml(url)}">${escapeHtml(title)}</span>
                <button type="button" class="chip-remove" aria-label="削除">×</button>
            </span>
        `;
        this.chipBox.querySelector('.chip-remove').addEventListener('click', () => this.clearChip());
        this.wrap.classList.add('has-chip');
    }

    clearChip() {
        this.hidden.value = '';
        this.chipBox.innerHTML = '';
        this.wrap.classList.remove('has-chip');
        this.input.focus();
    }

    show() { this.list.hidden = false; }
    hide() { this.list.hidden = true; this.activeIdx = -1; }
}

function escapeRegex(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function escapeHtml(s) {
    return String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}