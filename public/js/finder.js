/* public/js/finder.js */

(function () {
    const form = document.getElementById('pathForm');
    const resultDiv = document.getElementById('result');
    const submitBtn = document.getElementById('submitBtn');
    const depthSlider = document.getElementById('depthSlider');
    const depthValueEl = document.getElementById('depthValue');
    const depthMaxEl = document.getElementById('depthMax');

    // 設定はBlade側からwindowに注入される
    const SUGGEST_URL = window.FINDER_CONFIG.suggestUrl;
    const STREAM_URL = window.FINDER_CONFIG.streamUrl;

    let currentSource = null;

    const startAuto = new Autocomplete({
        wrap: document.getElementById('start_wrap'),
        input: document.getElementById('start_input'),
        list: document.getElementById('start_suggest'),
        chip: document.getElementById('start_chip'),
        hidden: document.getElementById('start_url'),
        suggestUrl: SUGGEST_URL,
    });
    const goalAuto = new Autocomplete({
        wrap: document.getElementById('goal_wrap'),
        input: document.getElementById('goal_input'),
        list: document.getElementById('goal_suggest'),
        chip: document.getElementById('goal_chip'),
        hidden: document.getElementById('goal_url'),
        suggestUrl: SUGGEST_URL,
    });

    depthSlider.addEventListener('input', () => {
        const v = parseInt(depthSlider.value, 10);
        depthValueEl.textContent = v;
        depthMaxEl.textContent = v * 2;
    });

    document.getElementById('swapBtn').addEventListener('click', () => {
        const sState = startAuto.getChipState();
        const gState = goalAuto.getChipState();
        const sInput = document.getElementById('start_input').value;
        const gInput = document.getElementById('goal_input').value;

        // 両方クリアしてから入れ替え
        startAuto.clearChip();
        goalAuto.clearChip();

        if (gState) {
            startAuto.setChip(gState.title, gState.url);
        } else {
            document.getElementById('start_input').value = gInput;
        }

        if (sState) {
            goalAuto.setChip(sState.title, sState.url);
        } else {
            document.getElementById('goal_input').value = sInput;
        }
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();

        // ★ 既存の EventSource を確実に閉じる
        if (currentSource) {
            currentSource.close();
            currentSource = null;
        }

        const startUrl = document.getElementById('start_url').value.trim();
        const goalUrl = document.getElementById('goal_url').value.trim();

        if (!startUrl || !goalUrl) {
            alert('スタートとゴールの両方を選択してください。');
            return;
        }

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

        // ★ URL / URLSearchParams で構築して二重エンコードを防止
        const depth = parseInt(depthSlider.value, 10);
        const streamParams = new URLSearchParams();
        streamParams.set('start_url', startUrl);
        streamParams.set('goal_url', goalUrl);
        streamParams.set('depth', depth);
        const url = STREAM_URL + '?' + streamParams.toString();

        const source = new EventSource(url);
        currentSource = source;

        const startedAt = performance.now();
        let lastEventAt = startedAt;
        let timerRafId = null;
        let timerStopped = false;
        let lastDepth = 0;

        const tick = () => {
            if (timerStopped) return;
            const elapsedMs = performance.now() - startedAt;
            const sinceLastMs = performance.now() - lastEventAt;
            const timerEl = document.getElementById('elapsedTimer');
            if (timerEl) {
                timerEl.textContent = (elapsedMs / 1000).toFixed(1) + 's';
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

        source.addEventListener('cache_classify', (ev) => {
            touch();
            const d = JSON.parse(ev.data);
            const fresh = d.fresh, check = d.check, missing = d.missing;
            const total = fresh + check + missing;
            if (total > 0) {
                log(`📦 キャッシュ判定: 新鮮${fresh} / 確認${check} / 未取得${missing}`, 'fwd');
            }
            updateCounters(d);
        });

        source.addEventListener('cache_check_touched', (ev) => {
            touch();
            const d = JSON.parse(ev.data);
            log(`🔍 Wikipedia最終更新を確認中(${d.count}件)`, 'fwd');
            updateCounters(d);
        });

        source.addEventListener('fetching_links', (ev) => {
            touch();
            const d = JSON.parse(ev.data);
            log(`🌐 リンク取得開始(${d.count}ページ)`, 'fwd');
            updateCounters(d);
        });

        source.addEventListener('fetching_progress', (ev) => {
            touch();
            const d = JSON.parse(ev.data);
            if (d.current === 1 || d.current === d.total || d.current % 5 === 0) {
                log(`  ↳ ${d.current}/${d.total}: ${d.title}`, 'fwd');
            }
            updateCounters(d);
        });

        source.addEventListener('fetching_incoming', (ev) => {
            touch();
            const d = JSON.parse(ev.data);
            log(`🔄 入リンク取得中(${d.count}ページ)`, 'bwd');
            updateCounters(d);
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

        source.addEventListener('retry', (ev) => {
            touch();
            const d = JSON.parse(ev.data);
            const arrow = d.direction === 'forward' ? '→' : '←';
            const cls = d.direction === 'forward' ? 'fwd' : 'bwd';
            log(`⟳ [${d.direction}] フロンティアが空のためリトライ(${d.attempt}/2)`, cls);
            updateCounters(d);
        });

        source.addEventListener('done', () => {
            source.close();
            currentSource = null;
            stopTimer('done');
            submitBtn.disabled = false;
            submitBtn.textContent = '最短経路を探す';
        });

        source.onerror = () => {
            // ★ 重複クリーンアップを防ぐガード
            if (source !== currentSource) return;

            source.close();
            currentSource = null;
            stopTimer();
            submitBtn.disabled = false;
            submitBtn.textContent = '最短経路を探す';

            if (!document.querySelector('.error') && !document.querySelector('.success-banner')) {
                renderError('サーバーとの接続が切断されました。再度お試しください。');
            }
        };
    });

    function renderPath({ clicks, path }, finalDepth) {
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
})();