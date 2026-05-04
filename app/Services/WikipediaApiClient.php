<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wikipedia API への通信を担当するクライアント。
 * BFSアルゴリズムやキャッシュ判定からは独立している。
 */
class WikipediaApiClient
{
    private const USER_AGENT = 'WikiPathFinder/1.0 (takeshilingmu027@gmail.com)';

    private string $lang;
    private string $apiUrl;
    private int $timeout;
    private int $poolSize;

    /** 累計APIリクエスト回数(統計用) */
    private int $apiCalls = 0;

    /** 最後にリクエストを送った時刻(マイクロ秒) */
    private float $lastRequestAt = 0;

    /** リクエスト間の最小間隔(マイクロ秒) - アダプティブに増減 */
    private int $throttleUs = 1_000_000;  // 1s（初期値）
    private int $baseThrottleUs = 1_000_000;
    private int $maxThrottleUs = 5_000_000;  // 5s（上限）

    public function __construct(string $lang = 'ja')
    {
        $this->lang = $lang;
        $this->apiUrl = "https://{$lang}.wikipedia.org/w/api.php";
        $this->timeout = (int) config('finder.timeout', 15);
        $this->poolSize = (int) config('finder.pool_size', 20);
    }

    public function getApiCalls(): int
    {
        return $this->apiCalls;
    }

    public function resetApiCalls(): void
    {
        $this->apiCalls = 0;
    }

    /**
     * タイトルを正規化(リダイレクト追従)
     * @return string|null  正規化されたタイトル、なければnull
     */
    public function normalizeTitle(string $title): ?string
    {
        $response = $this->get([
            'action' => 'query',
            'titles' => $title,
            'redirects' => 1,
            'format' => 'json',
            'formatversion' => 2,
        ]);

        if (!$response) {
            Log::warning('[WikipediaApiClient] normalizeTitle: no response', ['title' => $title]);
            return null;
        }
        if (!$response->ok()) {
            Log::warning('[WikipediaApiClient] normalizeTitle: HTTP error', [
                'title'  => $title,
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $page = $response->json('query.pages.0');
        if (!$page) {
            Log::warning('[WikipediaApiClient] normalizeTitle: no page in response', [
                'title' => $title,
                'body'  => substr($response->body(), 0, 500),
            ]);
            return null;
        }
        if (isset($page['missing'])) {
            Log::info('[WikipediaApiClient] normalizeTitle: page missing', ['title' => $title]);
            return null;
        }
        if (isset($page['invalid'])) {
            Log::warning('[WikipediaApiClient] normalizeTitle: invalid title', ['title' => $title]);
            return null;
        }
        if (($page['ns'] ?? null) !== 0) {
            Log::warning('[WikipediaApiClient] normalizeTitle: not main namespace', [
                'title' => $title,
                'ns'    => $page['ns'] ?? 'null',
            ]);
            return null;
        }

        return $page['title'];
    }

    /**
     * 1ページの全リンクを取得(plcontinueを使ってページネーション)
     *
     * @return array  ['title' => 'リンク先タイトル', ...] のリンク先タイトル配列
     */
    public function getAllOutgoingLinks(string $title): array
    {
        $allLinks = [];
        $plcontinue = null;

        do {
            $params = [
                'action' => 'query',
                'titles' => $title,
                'prop' => 'links',
                'pllimit' => 'max',
                'plnamespace' => 0,
                'format' => 'json',
                'formatversion' => 2,
            ];
            if ($plcontinue !== null) {
                $params['plcontinue'] = $plcontinue;
            }

            $response = $this->get($params);
            if (!$response || !$response->ok()) {
                // 途中で失敗した場合、それまでに集めたリンクは返す(部分的な結果)
                Log::warning('[WikipediaApiClient] getAllOutgoingLinks partial failure', [
                    'title' => $title,
                    'collected_so_far' => count($allLinks),
                ]);
                break;
            }

            $data = $response->json();

            // ページが存在しない場合
            $page = $data['query']['pages'][0] ?? null;
            if (!$page || isset($page['missing']))
                break;

            foreach (($page['links'] ?? []) as $link) {
                if (isset($link['title'])) {
                    $allLinks[] = $link['title'];
                }
            }

            // 続きがあるか?
            $plcontinue = $data['continue']['plcontinue'] ?? null;
        } while ($plcontinue !== null);

        return $allLinks;
    }

    /**
     * 1ページに入ってくる全リンクを取得(plcontinue相当の lhcontinue)
     */
    public function getAllIncomingLinks(string $title): array
    {
        $allLinks = [];
        $lhcontinue = null;

        do {
            $params = [
                'action' => 'query',
                'titles' => $title,
                'prop' => 'linkshere',
                'lhlimit' => 'max',
                'lhnamespace' => 0,
                'lhshow' => '!redirect',
                'format' => 'json',
                'formatversion' => 2,
            ];
            if ($lhcontinue !== null) {
                $params['lhcontinue'] = $lhcontinue;
            }

            $response = $this->get($params);
            if (!$response || !$response->ok()) {
                Log::warning('[WikipediaApiClient] getAllIncomingLinks partial failure', [
                    'title' => $title,
                    'collected_so_far' => count($allLinks),
                ]);
                break;
            }

            $data = $response->json();

            $page = $data['query']['pages'][0] ?? null;
            if (!$page || isset($page['missing']))
                break;

            foreach (($page['linkshere'] ?? []) as $link) {
                if (isset($link['title'])) {
                    $allLinks[] = $link['title'];
                }
            }

            $lhcontinue = $data['continue']['lhcontinue'] ?? null;
        } while ($lhcontinue !== null);

        return $allLinks;
    }

    /**
     * 複数ページの入リンクを一括取得（バッチ版）。
     * 最大50タイトルをパイプ区切りで1リクエストにまとめ、API呼び出し回数を大幅に削減する。
     *
     * @param string[] $titles  ターゲットページのタイトル配列
     * @return array  title => [incoming_title, ...] の連想配列
     */
    public function getBatchIncomingLinks(array $titles): array
    {
        $result = array_fill_keys($titles, []);

        // ★ incoming はページあたりのリンク数が多く lhcontinue が膨れるため、
        //   バッチサイズを小さくして1チャンクあたりの継続回数を抑える。
        //   lhlimit=max(500) ÷ 10タイトル = 1ページあたり平均50件/回、
        //   50タイトルだと平均10件/回になり継続が爆発する。
        foreach (array_chunk($titles, 10) as $chunk) {
            $lhcontinue = null;

            do {
                $params = [
                    'action' => 'query',
                    'titles' => implode('|', $chunk),
                    'prop' => 'linkshere',
                    'lhlimit' => 'max',
                    'lhnamespace' => 0,
                    'lhshow' => '!redirect',
                    'format' => 'json',
                    'formatversion' => 2,
                ];
                if ($lhcontinue !== null) {
                    $params['lhcontinue'] = $lhcontinue;
                }

                $response = $this->get($params);
                if (!$response || !$response->ok()) {
                    Log::warning('[WikipediaApiClient] getBatchIncomingLinks partial failure', [
                        'chunk_size' => count($chunk),
                        'lhcontinue' => $lhcontinue,
                    ]);
                    break;
                }

                $data = $response->json();

                foreach ($data['query']['pages'] ?? [] as $page) {
                    if (isset($page['missing'])) continue;
                    $pageTitle = $page['title'] ?? null;
                    if ($pageTitle === null) continue;

                    foreach ($page['linkshere'] ?? [] as $link) {
                        if (isset($link['title'])) {
                            $result[$pageTitle][] = $link['title'];
                        }
                    }
                }

                $lhcontinue = $data['continue']['lhcontinue'] ?? null;
            } while ($lhcontinue !== null);
        }

        return $result;
    }

    /**
     * 複数ページの touched(最終更新日時)を一括取得
     *
     * @return array  title => Carbon|null の連想配列
     */
    public function getTouchedTimes(array $titles): array
    {
        if (empty($titles))
            return [];

        $result = array_fill_keys($titles, null);

        // Wikipedia API は titles を | 区切りで複数指定できる(最大50件まで)
        foreach (array_chunk($titles, 50) as $chunk) {
            $response = $this->get([
                'action' => 'query',
                'titles' => implode('|', $chunk),
                'prop' => 'info',
                'format' => 'json',
                'formatversion' => 2,
            ]);

            if (!$response || !$response->ok())
                continue;

            foreach (($response->json('query.pages', []) ?? []) as $page) {
                if (isset($page['title'], $page['touched']) && !isset($page['missing'])) {
                    $result[$page['title']] = Carbon::parse($page['touched']);
                }
            }
        }

        return $result;
    }

    /**
     * オートコンプリート用の候補取得(OpenSearch API)
     *
     * @return array  [['title' => '...', 'url' => '...'], ...]
     */
    public function suggest(string $query, int $limit = 10): array
    {
        $response = $this->get([
            'action' => 'opensearch',
            'search' => $query,
            'limit' => $limit,
            'namespace' => 0,
            'format' => 'json',
        ]);

        if (!$response || !$response->ok())
            return [];

        $data = $response->json();
        $titles = $data[1] ?? [];
        $urls = $data[3] ?? [];

        $suggestions = [];
        foreach ($titles as $i => $title) {
            $suggestions[] = [
                'title' => $title,
                'url' => $urls[$i] ?? '',
            ];
        }
        return $suggestions;
    }

    /**
     * 内部メソッド: 1リクエスト送信
     *
     * - 429 (Rate Limit): 長めのバックオフ + グローバルスロットル引き上げ
     * - 5xx: 通常のリトライ
     * - 4xx (429以外): 即返却
     * - リクエスト間スロットリング: アダプティブに調整
     */
    private function get(array $params): ?Response
    {
        $maxAttempts = 4;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // ★ スロットリング: 前回リクエストから最低間隔を空ける
                $this->throttle();

                $this->apiCalls++;
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout($this->timeout)
                    ->get($this->apiUrl, $params);

                // HTTP的に成功 (2xx) ならそのまま返す
                if ($response->ok()) {
                    // ★ 成功したらスロットルを徐々に下げる
                    $this->easeThrottle();
                    return $response;
                }

                // ★ 429 (Rate Limit) または 5xx → リトライ
                $status = $response->status();
                $isRetryable = $status === 429 || $response->serverError();

                if ($isRetryable && $attempt < $maxAttempts) {
                    if ($status === 429) {
                        // ★ 429: グローバルスロットルを引き上げ
                        $this->raiseThrottle();
                    }
                    // ★ Retry-After ヘッダーがあればその秒数を優先
                    $backoffMs = $this->resolveBackoffMs($response, $attempt, $status);
                    Log::warning('[WikipediaApiClient] retryable error, backing off', [
                        'status'       => $status,
                        'attempt'      => $attempt,
                        'retry_after'  => $response->header('Retry-After'),
                        'backoff_ms'   => $backoffMs,
                        'throttle_ms'  => (int) ($this->throttleUs / 1000),
                    ]);
                    usleep($backoffMs * 1000);
                    continue;
                }

                if (!$isRetryable || $attempt >= $maxAttempts) {
                    if ($status === 429 || $response->serverError()) {
                        Log::warning('[WikipediaApiClient] retryable error exhausted', [
                            'status'  => $status,
                            'attempt' => $attempt,
                        ]);
                    }
                    return $response;
                }

            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    $backoffMs = $this->backoffMs($attempt, 0);
                    Log::warning('[WikipediaApiClient] HTTP exception, backing off', [
                        'message'    => $e->getMessage(),
                        'attempt'    => $attempt,
                        'backoff_ms' => $backoffMs,
                    ]);
                    usleep($backoffMs * 1000);
                    continue;
                }
            }
        }

        Log::warning('[WikipediaApiClient] all attempts failed', [
            'message' => $lastException?->getMessage(),
            'params' => $params,
        ]);
        return null;
    }

    /**
     * レスポンスの Retry-After ヘッダーを優先し、なければ指数バックオフで待機時間を決定。
     */
    private function resolveBackoffMs(?Response $response, int $attempt, int $status): int
    {
        if ($response && $status === 429) {
            $retryAfter = $response->header('Retry-After');
            if ($retryAfter !== null && $retryAfter !== '') {
                // Retry-After は秒数（整数）または HTTP-date
                if (is_numeric($retryAfter)) {
                    $waitMs = ((int) $retryAfter) * 1000;
                } else {
                    // HTTP-date をパース
                    $timestamp = strtotime($retryAfter);
                    $waitMs = $timestamp ? max(0, ($timestamp - time()) * 1000) : 0;
                }
                // 最低 1 秒、最大 60 秒
                if ($waitMs > 0) {
                    return max(1000, min(60_000, $waitMs));
                }
            }
        }

        // Retry-After がなければフォールバック
        return $this->backoffMs($attempt, $status);
    }

    /**
     * フォールバック用の指数バックオフ(ミリ秒)。
     */
    private function backoffMs(int $attempt, int $status): int
    {
        // 429: 5s → 10s → 20s
        // 5xx: 500ms → 1s → 2s
        $baseMs = $status === 429 ? 5000 : 500;
        return $baseMs * (1 << ($attempt - 1));
    }

    /**
     * リクエスト間スロットリング。前回リクエストからの経過時間が足りなければ待つ。
     */
    private function throttle(): void
    {
        if ($this->lastRequestAt > 0) {
            $elapsed = (microtime(true) - $this->lastRequestAt) * 1_000_000;
            if ($elapsed < $this->throttleUs) {
                usleep((int) ($this->throttleUs - $elapsed));
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    /**
     * 429 発生時: グローバルスロットルを引き上げる
     */
    private function raiseThrottle(): void
    {
        $this->throttleUs = min($this->maxThrottleUs, $this->throttleUs * 2);
    }

    /**
     * 成功時: スロットルを徐々に元に戻す
     */
    private function easeThrottle(): void
    {
        if ($this->throttleUs > $this->baseThrottleUs) {
            // 急に下げると再び 429 になるので、10% ずつ下げる
            $this->throttleUs = max(
                $this->baseThrottleUs,
                (int) ($this->throttleUs * 0.9)
            );
        }
    }
}