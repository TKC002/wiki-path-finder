<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wikipedia 6クリック挑戦</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 2.5rem 1rem;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #2d3748;
            line-height: 1.6;
        }

        .container {
            max-width: 760px;
            margin: 0 auto;
        }

        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            padding: 2.5rem;
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.75rem;
        }

        .subtitle {
            color: #718096;
            margin-bottom: 1.75rem;
            font-size: 0.95rem;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #4a5568;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        input[type=url] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.15s;
        }

        input[type=url]:focus {
            outline: none;
            border-color: #667eea;
        }

        button {
            width: 100%;
            padding: 0.9rem 1rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
            margin-top: 0.25rem;
        }

        button:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(102, 126, 234, 0.4);
        }

        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .hint {
            margin-top: 1rem;
            background: #fffbea;
            color: #744210;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border-left: 4px solid #ecc94b;
            font-size: 0.85rem;
        }

        #result {
            margin-top: 2rem;
        }

        #result:empty {
            display: none;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #667eea;
        }

        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #c53030;
        }

        .success-banner {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: #fff;
            padding: 1.25rem;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .success-banner .clicks-num {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            display: block;
        }

        .success-banner .clicks-label {
            font-size: 1rem;
            opacity: 0.95;
        }

        .path-container {
            padding: 1.5rem;
            background: #f7fafc;
            border-radius: 8px;
        }

        .path-step {
            text-align: center;
        }

        .path-step a {
            display: inline-block;
            background: #fff;
            color: #5a67d8;
            padding: 0.7rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 2px solid #5a67d8;
            transition: all 0.15s;
            max-width: 100%;
            word-break: break-word;
        }

        .path-step a:hover {
            background: #5a67d8;
            color: #fff;
        }

        .path-step.start a {
            border-color: #38a169;
            color: #38a169;
        }

        .path-step.start a:hover {
            background: #38a169;
            color: #fff;
        }

        .path-step.goal a {
            border-color: #e53e3e;
            color: #e53e3e;
        }

        .path-step.goal a:hover {
            background: #e53e3e;
            color: #fff;
        }

        .path-arrow {
            font-size: 1.5rem;
            color: #a0aec0;
            margin: 0.6rem 0;
            text-align: center;
        }

        .progress {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }

        .progress h3 {
            margin: 0 0 0.75rem;
            font-size: 0.95rem;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress h3 .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #48bb78;
            animation: pulse 1s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.3;
            }
        }

        .progress-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .progress-stat {
            background: #fff;
            border-radius: 6px;
            padding: 0.6rem;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .progress-stat .num {
            font-size: 1.5rem;
            font-weight: 700;
            color: #5a67d8;
            line-height: 1;
        }

        .progress-stat .label {
            font-size: 0.72rem;
            color: #718096;
            margin-top: 0.3rem;
        }

        .progress-log {
            max-height: 180px;
            overflow-y: auto;
            background: #1a202c;
            color: #e2e8f0;
            border-radius: 6px;
            padding: 0.6rem 0.8rem;
            font-family: ui-monospace, "SF Mono", Consolas, monospace;
            font-size: 0.78rem;
            line-height: 1.5;
        }

        .progress-log .line {
            white-space: pre-wrap;
        }

        .progress-log .fwd {
            color: #68d391;
        }

        .progress-log .bwd {
            color: #f6ad55;
        }

        .progress-log .meet {
            color: #f687b3;
            font-weight: 700;
        }

        .progress h3 {
            justify-content: space-between;
            /* タイマーを右寄せにする */
        }

        .progress h3 .title-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .elapsed-timer {
            font-family: ui-monospace, "SF Mono", Consolas, monospace;
            font-size: 0.85rem;
            color: #4a5568;
            background: #edf2f7;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-variant-numeric: tabular-nums;
        }

        .elapsed-timer.stalled {
            background: #fed7d7;
            color: #c53030;
        }

        .elapsed-timer.done {
            background: #c6f6d5;
            color: #22543d;
        }

        .suggest-wrap {
            position: relative;
        }

        .suggest-list {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            max-height: 280px;
            overflow-y: auto;
            z-index: 50;
        }

        .suggest-item {
            padding: 0.55rem 0.85rem;
            cursor: pointer;
            font-size: 0.92rem;
            border-bottom: 1px solid #f1f5f9;
            color: #2d3748;
            line-height: 1.3;
        }

        .suggest-item:last-child {
            border-bottom: none;
        }

        .suggest-item:hover,
        .suggest-item.active {
            background: #edf2f7;
            color: #5a67d8;
        }

        .suggest-item .match {
            color: #5a67d8;
            font-weight: 700;
        }

        .suggest-empty,
        .suggest-loading {
            padding: 0.7rem 0.85rem;
            color: #a0aec0;
            font-size: 0.85rem;
            font-style: italic;
        }

        .suggest-wrap {
            position: relative;
            width: 100%;
        }

        .suggest-wrap .suggest-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.15s;
        }

        .suggest-wrap .suggest-input:focus {
            outline: none;
            border-color: #667eea;
        }

        /* チップ表示モード */
        .suggest-wrap.has-chip .suggest-input {
            display: none;
        }

        .suggest-wrap:not(.has-chip) .chip-display {
            display: none;
        }

        .chip-display {
            width: 100%;
            min-height: calc(0.95rem * 1.5 + 0.75rem * 2 + 4px);
            /* inputと同じ高さ */
            padding: 0.5rem 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: #f7fafc;
            display: flex;
            align-items: center;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            padding: 0.4rem 0.4rem 0.4rem 0.85rem;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 600;
            max-width: 100%;
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3);
        }

        .chip-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 50ch;
        }

        .chip-remove {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            padding: 0;
            transition: background 0.15s;
        }

        .chip-remove:hover {
            background: rgba(255, 255, 255, 0.45);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <h1>🔗 Wikipedia 6クリック挑戦</h1>
            <p class="subtitle">
                Wikipediaのどのページからどのページへも6クリック以内で移動できる、という噂を確かめよう。
            </p>

            <form id="pathForm">
                <div class="form-group">
                    <label for="start_input">🚀 スタートページ</label>
                    <div class="suggest-wrap" id="start_wrap">
                        <input type="text" id="start_input" class="suggest-input" autocomplete="off"
                            placeholder="キーワード(例: 日本)または https://ja.wikipedia.org/wiki/日本">
                        <div class="chip-display" id="start_chip"></div>
                        <input type="hidden" id="start_url" name="start_url">
                        <div class="suggest-list" id="start_suggest" hidden></div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="goal_input">🎯 ゴールページ</label>
                    <div class="suggest-wrap" id="goal_wrap">
                        <input type="text" id="goal_input" class="suggest-input" autocomplete="off"
                            placeholder="キーワード(例: アーネスト・ヘミングウェイ)または完全なURL">
                        <div class="chip-display" id="goal_chip"></div>
                        <input type="hidden" id="goal_url" name="goal_url">
                        <div class="suggest-list" id="goal_suggest" hidden></div>
                    </div>
                </div>
                <button type="submit" id="submitBtn">最短経路を探す</button>
            </form>

            <div class="hint">
                ⚠️ Wikipedia APIへの多数のリクエストが必要なため、組み合わせによっては数十秒〜数分かかります。
                無関係なページ同士ほど時間がかかります。
            </div>

            <div id="result"></div>
        </div>
    </div>

    <script>
        const form = document.getElementById('pathForm');
        const resultDiv = document.getElementById('result');
        const submitBtn = document.getElementById('submitBtn');
        let currentSource = null;
        class Autocomplete {
            constructor(opts) {
                this.wrap = opts.wrap;        // .suggest-wrap
                this.input = opts.input;       // 表示用 input
                this.list = opts.list;        // .suggest-list
                this.chipBox = opts.chip;        // .chip-display
                this.hidden = opts.hidden;      // hidden input(送信値)
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
                // URL直貼りも検知してチップ化
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
                        '{{ route("finder.suggest") }}?q=' + encodeURIComponent(q) + '&lang=ja',
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

            /** URL直貼りからタイトル抽出してチップ化 */
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

            /** チップ表示モードへ */
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

            /** チップを消して入力モードに戻す */
            clearChip() {
                this.hidden.value = '';
                this.chipBox.innerHTML = '';
                this.wrap.classList.remove('has-chip');
                this.input.focus();
            }

            show() { this.list.hidden = false; }
            hide() { this.list.hidden = true; this.activeIdx = -1; }
        }

        const startAuto = new Autocomplete({
            wrap: document.getElementById('start_wrap'),
            input: document.getElementById('start_input'),
            list: document.getElementById('start_suggest'),
            chip: document.getElementById('start_chip'),
            hidden: document.getElementById('start_url'),
        });
        const goalAuto = new Autocomplete({
            wrap: document.getElementById('goal_wrap'),
            input: document.getElementById('goal_input'),
            list: document.getElementById('goal_suggest'),
            chip: document.getElementById('goal_chip'),
            hidden: document.getElementById('goal_url'),
        });
        function escapeRegex(s) {
            return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();

            if (currentSource) currentSource.close();

            // ★ 修正: hidden input から取る
            const startUrl = document.getElementById('start_url').value.trim();
            const goalUrl = document.getElementById('goal_url').value.trim();

            // ★ 追加: 選択されていなければ警告
            if (!startUrl || !goalUrl) {
                alert('スタートとゴールの両方を選択してください。');
                return;
            }

            // ★ 進捗パネル(タイマー追加)
            resultDiv.innerHTML = `
                <div class="progress" id="progressPanel">
                    <h3>
                        <span class="title-left"><span class="dot"></span><span id="progressTitle">接続中…</span></span>
                        <span class="elapsed-timer" id="elapsedTimer">0.0s</span>
                    </h3>
                    <div class="progress-grid">
                        <div class="progress-stat"><div class="num" id="statDepth">0</div><div class="label">現在の層</div></div>
                        <div class="progress-stat"><div class="num" id="statVisited">0</div><div class="label">探索ノード数</div></div>
                        <div class="progress-stat"><div class="num" id="statApi">0</div><div class="label">APIリクエスト</div></div>
                    </div>
                    <div class="progress-log" id="progressLog"></div>
                </div>
            `;
            submitBtn.disabled = true;
            submitBtn.textContent = '探索中…';

            const url = '{{ route("finder.stream") }}'
                + '?start_url=' + encodeURIComponent(startUrl)
                + '&goal_url=' + encodeURIComponent(goalUrl);

            const source = new EventSource(url);
            currentSource = source;

            const startedAt = performance.now();
            let lastEventAt = startedAt;     // 最後にサーバーから何か来た時刻
            let timerRafId = null;
            let timerStopped = false;
            let lastDepth = 0;

            // ★ タイマー更新ループ
            const tick = () => {
                if (timerStopped) return;
                const elapsedMs = performance.now() - startedAt;
                const sinceLastMs = performance.now() - lastEventAt;
                const timerEl = document.getElementById('elapsedTimer');
                if (timerEl) {
                    timerEl.textContent = (elapsedMs / 1000).toFixed(1) + 's';
                    // 5秒以上サーバーから音沙汰なし → 警告色
                    timerEl.classList.toggle('stalled', sinceLastMs > 5000);
                }
                timerRafId = requestAnimationFrame(tick);
            };
            timerRafId = requestAnimationFrame(tick);

            const stopTimer = (state) => {
                timerStopped = true;
                if (timerRafId) cancelAnimationFrame(timerRafId);
                const timerEl = document.getElementById('elapsedTimer');
                if (timerEl) {
                    timerEl.classList.remove('stalled');
                    if (state === 'done') timerEl.classList.add('done');
                    const final = ((performance.now() - startedAt) / 1000).toFixed(2);
                    timerEl.textContent = final + 's';
                }
            };

            const setStat = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val;
            };
            const log = (msg, cls = '') => {
                const box = document.getElementById('progressLog');
                if (!box) return;
                const line = document.createElement('div');
                line.className = 'line ' + cls;
                const t = ((performance.now() - startedAt) / 1000).toFixed(2);
                line.textContent = `[${t.padStart(6)}s] ${msg}`;
                box.appendChild(line);
                box.scrollTop = box.scrollHeight;
            };
            const updateCounters = (d) => {
                if (typeof d.visited_count === 'number') setStat('statVisited', d.visited_count.toLocaleString());
                if (typeof d.api_calls === 'number') setStat('statApi', d.api_calls.toLocaleString());
            };
            // ★ 何かイベントが来るたびに「最後の音沙汰」を更新
            const touch = () => { lastEventAt = performance.now(); };

            source.addEventListener('connected', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                document.getElementById('progressTitle').textContent = '探索を開始します';
                log(`接続完了: ${d.start.title} → ${d.goal.title}`);
            });

            source.addEventListener('normalize', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                log(`タイトル正規化(${d.role}): ${d.title}`);
                updateCounters(d);
            });

            source.addEventListener('search_start', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                log(`探索開始: 「${d.start}」 ⇄ 「${d.goal}」(最大${d.max_depth_total}クリック)`);
            });

            source.addEventListener('layer_start', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                lastDepth = d.total_depth;
                setStat('statDepth', d.total_depth);
                const arrow = d.direction === 'forward' ? '→' : '←';
                const cls = d.direction === 'forward' ? 'fwd' : 'bwd';
                document.getElementById('progressTitle').textContent =
                    `第${d.total_depth}層を探索中(${d.direction === 'forward' ? '前方' : '後方'} 深度${d.depth})`;
                log(`${arrow} 第${d.total_depth}層 [${d.direction}] フロンティア=${d.frontier_size.toLocaleString()}件を展開`, cls);
                updateCounters(d);
            });

            source.addEventListener('chunk_done', (ev) => {
                touch();
                updateCounters(JSON.parse(ev.data));
            });

            source.addEventListener('layer_end', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                const cls = d.direction === 'forward' ? 'fwd' : 'bwd';
                log(`  ↳ 完了: 次フロンティア=${d.new_frontier_size.toLocaleString()}件`, cls);
                updateCounters(d);
            });

            source.addEventListener('meeting', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                log(`★ 中間で出会いました: 「${d.node}」`, 'meet');
            });

            source.addEventListener('result', (ev) => {
                touch();
                const d = JSON.parse(ev.data);
                renderPath(d, lastDepth);
            });

            source.addEventListener('error', (ev) => {
                if (!ev.data) return;
                try {
                    touch();
                    const d = JSON.parse(ev.data);
                    renderError(d.message || '不明なエラーが発生しました。');
                } catch (_) { }
            });

            source.addEventListener('done', () => {
                source.close();
                currentSource = null;
                stopTimer('done');         // ★ タイマー停止
                submitBtn.disabled = false;
                submitBtn.textContent = '最短経路を探す';
            });

            source.onerror = () => {
                if (source.readyState === EventSource.CLOSED) {
                    stopTimer();           // ★ 接続切れでも停止
                    submitBtn.disabled = false;
                    submitBtn.textContent = '最短経路を探す';
                }
            };
        });
        function renderPath({ clicks, path }, finalDepth) {
            // 進捗パネルを完了状態にしてから結果を下に追加
            const panel = document.getElementById('progressPanel');
            if (panel) {
                panel.querySelector('.dot').style.background = '#48bb78';
                panel.querySelector('.dot').style.animation = 'none';
                document.getElementById('progressTitle').textContent = `探索完了(到達層: ${finalDepth || clicks})`;
            }
            const last = path.length - 1;
            let html = `
                <div class="success-banner" style="margin-top:1rem;">
                    <span class="clicks-num">${clicks}</span>
                    <span class="clicks-label">クリックで到達できます!</span>
                </div>
                <div class="path-container">
            `;
            path.forEach((step, i) => {
                const cls = i === 0 ? 'path-step start' : (i === last ? 'path-step goal' : 'path-step');
                html += `<div class="${cls}"><a href="${escapeHtml(step.url)}" target="_blank" rel="noopener">${escapeHtml(step.title)}</a></div>`;
                if (i < last) html += '<div class="path-arrow">↓</div>';
            });
            html += '</div>';
            resultDiv.insertAdjacentHTML('beforeend', html);
        }

        function renderError(msg) {
            resultDiv.insertAdjacentHTML('beforeend',
                `<div class="error" style="margin-top:1rem;">❌ ${escapeHtml(msg)}</div>`);
        }

        function escapeHtml(s) {
            return String(s)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }
    </script>
</body>

</html>