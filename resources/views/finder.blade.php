<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wikipedia 6クリック挑戦</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 2.5rem 1rem;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #2d3748;
            line-height: 1.6;
        }
        .container { max-width: 760px; margin: 0 auto; }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            padding: 2.5rem;
        }
        h1 { margin: 0 0 0.5rem; font-size: 1.75rem; }
        .subtitle { color: #718096; margin-bottom: 1.75rem; font-size: 0.95rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; font-size: 0.9rem; color: #4a5568; }
        .form-group { margin-bottom: 1.25rem; }
        input[type=url] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.15s;
        }
        input[type=url]:focus { outline: none; border-color: #667eea; }
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
        button:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(102,126,234,0.4); }
        button:disabled { opacity: 0.6; cursor: not-allowed; }

        .hint {
            margin-top: 1rem;
            background: #fffbea;
            color: #744210;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border-left: 4px solid #ecc94b;
            font-size: 0.85rem;
        }
        #result { margin-top: 2rem; }
        #result:empty { display: none; }

        .loading { text-align: center; padding: 2rem; color: #667eea; }
        .spinner {
            width: 36px; height: 36px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

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
        .success-banner .clicks-num { font-size: 2.5rem; font-weight: 800; line-height: 1; display: block; }
        .success-banner .clicks-label { font-size: 1rem; opacity: 0.95; }

        .path-container {
            padding: 1.5rem;
            background: #f7fafc;
            border-radius: 8px;
        }
        .path-step { text-align: center; }
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
        .path-step a:hover { background: #5a67d8; color: #fff; }
        .path-step.start a { border-color: #38a169; color: #38a169; }
        .path-step.start a:hover { background: #38a169; color: #fff; }
        .path-step.goal a { border-color: #e53e3e; color: #e53e3e; }
        .path-step.goal a:hover { background: #e53e3e; color: #fff; }
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
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #48bb78;
            animation: pulse 1s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
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
        .progress-stat .num { font-size: 1.5rem; font-weight: 700; color: #5a67d8; line-height: 1; }
        .progress-stat .label { font-size: 0.72rem; color: #718096; margin-top: 0.3rem; }
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
        .progress-log .line { white-space: pre-wrap; }
        .progress-log .fwd { color: #68d391; }
        .progress-log .bwd { color: #f6ad55; }
        .progress-log .meet { color: #f687b3; font-weight: 700; }
        .progress h3 {
            justify-content: space-between; /* タイマーを右寄せにする */
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
                    <label for="start_url">🚀 スタートページのURL</label>
                    <input type="url" id="start_url" name="start_url" required
                           placeholder="https://ja.wikipedia.org/wiki/日本">
                </div>
                <div class="form-group">
                    <label for="goal_url">🎯 ゴールページのURL</label>
                    <input type="url" id="goal_url" name="goal_url" required
                           placeholder="https://ja.wikipedia.org/wiki/宇宙">
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

        form.addEventListener('submit', (e) => {
            e.preventDefault();

            if (currentSource) currentSource.close();

            const startUrl = document.getElementById('start_url').value.trim();
            const goalUrl  = document.getElementById('goal_url').value.trim();

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
                + '&goal_url='  + encodeURIComponent(goalUrl);

            const source = new EventSource(url);
            currentSource = source;

            const startedAt    = performance.now();
            let lastEventAt    = startedAt;     // 最後にサーバーから何か来た時刻
            let timerRafId     = null;
            let timerStopped   = false;
            let lastDepth      = 0;

            // ★ タイマー更新ループ
            const tick = () => {
                if (timerStopped) return;
                const elapsedMs   = performance.now() - startedAt;
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
                if (typeof d.api_calls === 'number')     setStat('statApi', d.api_calls.toLocaleString());
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
                const cls   = d.direction === 'forward' ? 'fwd' : 'bwd';
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
                } catch (_) {}
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