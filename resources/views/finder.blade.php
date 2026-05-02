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
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const startUrl = document.getElementById('start_url').value.trim();
            const goalUrl  = document.getElementById('goal_url').value.trim();

            resultDiv.innerHTML = `
                <div class="loading">
                    <div class="spinner"></div>
                    <p>探索中… しばらくお待ちください</p>
                </div>`;
            submitBtn.disabled = true;
            submitBtn.textContent = '探索中…';

            try {
                const res = await fetch('{{ route("finder.find") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest', // ★ Laravel がAJAXとして扱う
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ start_url: startUrl, goal_url: goalUrl }),
                });

                const text = await res.text();
                let data = null;
                try { data = JSON.parse(text); } catch (_) { /* JSONでない */ }

                if (!res.ok || !data) {
                    // ★ 原因特定用に全部出す
                    const detail = data?.error
                        ?? data?.message
                        ?? text
                        ?? '(レスポンス本文なし)';
                    resultDiv.innerHTML = `
                        <div class="error">
                            <strong>❌ エラー (HTTP ${res.status})</strong>
                            <pre style="white-space:pre-wrap; margin-top:.5rem; font-size:.8rem;">${escapeHtml(String(detail)).slice(0, 4000)}</pre>
                        </div>`;
                    console.error('[finder] error', res.status, text);
                    return;
                }
                renderPath(data);
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ 通信エラー: ${escapeHtml(err.message)}</div>`;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = '最短経路を探す';
            }
        });

        function renderPath({ clicks, path }) {
            const last = path.length - 1;
            let html = `
                <div class="success-banner">
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
            resultDiv.innerHTML = html;
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