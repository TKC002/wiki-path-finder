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
    private const USER_AGENT = 'WikiPathFinder/1.0 (Laravel demo)';

    private string $lang;
    private string $apiUrl;
    private int $timeout;
    private int $poolSize;

    /** 累計APIリクエスト回数(統計用) */
    private int $apiCalls = 0;

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

    /** 内部メソッド: 1リクエスト送信(失敗時は1回だけリトライ) */
    private function get(array $params): ?Response
    {
        $maxAttempts = 2;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->apiCalls++;
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout($this->timeout)
                    ->get($this->apiUrl, $params);

                // HTTP的に成功 (2xx) ならそのまま返す
                if ($response->ok()) {
                    return $response;
                }

                // 5xx 系はリトライ価値あり、4xx は即諦め
                if ($response->serverError() && $attempt < $maxAttempts) {
                    Log::warning('[WikipediaApiClient] server error, retrying', [
                        'status' => $response->status(),
                        'attempt' => $attempt,
                    ]);
                    usleep(500_000); // 0.5秒待つ
                    continue;
                }

                return $response; // 4xx などはそのまま返す(呼び出し側でハンドリング)

            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    Log::warning('[WikipediaApiClient] HTTP error, retrying', [
                        'message' => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);
                    usleep(500_000);
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
}