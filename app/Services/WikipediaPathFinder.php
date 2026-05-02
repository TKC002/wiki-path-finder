<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WikipediaPathFinder
{
    /** 片側の最大探索深度(3 + 3 = 最大6クリックまで) */
    private const MAX_DEPTH_PER_SIDE = 3;
    /** 1階層あたりに展開するページの上限(爆発的な分岐を防ぐ) */
    private const MAX_FRONTIER = 200;
    /** HTTP並列リクエストのチャンクサイズ */
    private const POOL_SIZE = 20;
    /** 1リクエストのタイムアウト(秒) */
    private const TIMEOUT = 15;

    private string $lang;
    private string $apiUrl;

    public function __construct(string $lang = 'ja')
    {
        $this->lang   = $lang;
        $this->apiUrl = "https://{$lang}.wikipedia.org/w/api.php";
    }

    /** WikipediaのURLを {lang, title} に分解 */
    public static function parseUrl(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('~^https?://([a-z-]+)\.wikipedia\.org/wiki/([^?#\s]+)~u', $url, $m)) {
            return null;
        }
        $title = str_replace('_', ' ', rawurldecode($m[2]));
        return ['lang' => $m[1], 'title' => $title];
    }

    /** タイトルをWikipediaのURLに戻す */
    public function titleToUrl(string $title): string
    {
        $path = rawurlencode(str_replace(' ', '_', $title));
        // スラッシュ・コロンはそのままの方が見やすいので復元
        $path = str_replace(['%2F', '%3A'], ['/', ':'], $path);
        return "https://{$this->lang}.wikipedia.org/wiki/{$path}";
    }

    /**
     * 双方向BFSでスタート→ゴールの最短経路を探索する。
     * 戻り値: ['path' => [...], 'clicks' => N]  または ['error' => '...']
     */
    public function findPath(string $startTitle, string $goalTitle): array
    {
        $start = $this->normalizeTitle($startTitle);
        if ($start === null) {
            return ['error' => "スタートページ「{$startTitle}」が見つかりません。"];
        }
        $goal = $this->normalizeTitle($goalTitle);
        if ($goal === null) {
            return ['error' => "ゴールページ「{$goalTitle}」が見つかりません。"];
        }
        if ($start === $goal) {
            return ['path' => [$start], 'clicks' => 0];
        }

        // forward:  node => parent(そのnodeにリンクしてきた親)
        // backward: node => child (forward方向で次に進むべきページ)
        $fwdParents  = [$start => null];
        $bwdChildren = [$goal  => null];

        $fwdFrontier = [$start];
        $bwdFrontier = [$goal];

        $fwdDepth = 0;
        $bwdDepth = 0;

        while ($fwdDepth < self::MAX_DEPTH_PER_SIDE || $bwdDepth < self::MAX_DEPTH_PER_SIDE) {
            // 小さい方のフロンティアを展開(双方向BFSのコツ)
            $expandForward = (count($fwdFrontier) <= count($bwdFrontier))
                ? ($fwdDepth < self::MAX_DEPTH_PER_SIDE)
                : ($bwdDepth >= self::MAX_DEPTH_PER_SIDE);

            if ($expandForward) {
                $meeting = $this->expandForward($fwdFrontier, $fwdParents, $bwdChildren);
                $fwdDepth++;
                if ($meeting['found']) {
                    return $this->buildPath($fwdParents, $bwdChildren, $meeting['node']);
                }
                $fwdFrontier = $meeting['frontier'];
            } else {
                $meeting = $this->expandBackward($bwdFrontier, $bwdChildren, $fwdParents);
                $bwdDepth++;
                if ($meeting['found']) {
                    return $this->buildPath($fwdParents, $bwdChildren, $meeting['node']);
                }
                $bwdFrontier = $meeting['frontier'];
            }

            if (empty($fwdFrontier) && empty($bwdFrontier)) {
                break;
            }
        }

        return ['error' => '探索範囲内(最大' . (self::MAX_DEPTH_PER_SIDE * 2) . 'クリック)では経路が見つかりませんでした。'];
    }

    /** forward方向に1階層展開 */
    private function expandForward(array $frontier, array &$fwdParents, array $bwdChildren): array
    {
        $frontier = array_slice(array_values(array_unique($frontier)), 0, self::MAX_FRONTIER);
        $newFrontier = [];

        foreach ($this->getOutgoingLinksBatch($frontier) as $source => $links) {
            foreach ($links as $link) {
                if (array_key_exists($link, $fwdParents)) {
                    continue;
                }
                $fwdParents[$link] = $source;

                if (array_key_exists($link, $bwdChildren)) {
                    return ['found' => true, 'node' => $link, 'frontier' => []];
                }
                $newFrontier[] = $link;
            }
        }
        return ['found' => false, 'node' => null, 'frontier' => $newFrontier];
    }

    /** backward方向に1階層展開(linkshereで「このページへ来ているリンク」を取得) */
    private function expandBackward(array $frontier, array &$bwdChildren, array $fwdParents): array
    {
        $frontier = array_slice(array_values(array_unique($frontier)), 0, self::MAX_FRONTIER);
        $newFrontier = [];

        foreach ($this->getIncomingLinksBatch($frontier) as $target => $incomingPages) {
            foreach ($incomingPages as $page) {
                if (array_key_exists($page, $bwdChildren)) {
                    continue;
                }
                // page → target(forward方向)なので、pageからgoalへ進む次の一歩はtarget
                $bwdChildren[$page] = $target;

                if (array_key_exists($page, $fwdParents)) {
                    return ['found' => true, 'node' => $page, 'frontier' => []];
                }
                $newFrontier[] = $page;
            }
        }
        return ['found' => false, 'node' => null, 'frontier' => $newFrontier];
    }

    /** 出ていくリンクを並列で取得 */
    private function getOutgoingLinksBatch(array $titles): array
    {
        return $this->batchRequest($titles, [
            'prop'        => 'links',
            'pllimit'     => 'max',
            'plnamespace' => 0,
        ], 'links', 'title');
    }

    /** 入ってくるリンクを並列で取得 */
    private function getIncomingLinksBatch(array $titles): array
    {
        return $this->batchRequest($titles, [
            'prop'        => 'linkshere',
            'lhlimit'     => 'max',
            'lhnamespace' => 0,
            'lhshow'      => '!redirect',
        ], 'linkshere', 'title');
    }

    /** 共通バッチリクエスト */
    private function batchRequest(array $titles, array $extraParams, string $propKey, string $itemKey): array
    {
        $results = array_fill_keys($titles, []);
        $chunks  = array_chunk($titles, self::POOL_SIZE);

        foreach ($chunks as $chunk) {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($title) => $pool
                    ->withHeaders(['User-Agent' => 'WikiPathFinder/1.0 (Laravel demo)'])
                    ->timeout(self::TIMEOUT)
                    ->get($this->apiUrl, array_merge([
                        'action'        => 'query',
                        'titles'        => $title,
                        'format'        => 'json',
                        'formatversion' => 2,
                    ], $extraParams)),
                $chunk
            ));

            foreach ($chunk as $i => $title) {
                $r = $responses[$i] ?? null;
                if (!$r instanceof Response || !$r->ok()) {
                    continue;
                }
                $items = [];
                foreach ($r->json('query.pages', []) as $page) {
                    if (isset($page['missing'])) continue;
                    foreach (($page[$propKey] ?? []) as $e) {
                        if (isset($e[$itemKey])) {
                            $items[] = $e[$itemKey];
                        }
                    }
                }
                $results[$title] = $items;
            }
        }
        return $results;
    }

    /** タイトルを正規化(リダイレクト追従・スペース揺れの吸収) */
    private function normalizeTitle(string $title): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'User-Agent' => 'WikiPathFinder/1.0 (Laravel demo)',
                ])
                ->timeout(self::TIMEOUT)
                ->get($this->apiUrl, [
                    'action'        => 'query',
                    'titles'        => $title,
                    'redirects'     => 1,
                    'format'        => 'json',
                    'formatversion' => 2,
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[finder] normalizeTitle HTTP error', [
                'title'   => $title,
                'message' => $e->getMessage(),
            ]);
            throw $e; // ← 上のcatchで拾う
        }

        if (!$response->ok()) {
            \Illuminate\Support\Facades\Log::warning('[finder] normalizeTitle not ok', [
                'title'  => $title,
                'status' => $response->status(),
                'body'   => mb_substr((string) $response->body(), 0, 500),
            ]);
            return null;
        }

        $page = $response->json('query.pages.0');
        \Illuminate\Support\Facades\Log::info('[finder] normalizeTitle page', [
            'title' => $title,
            'page'  => $page,
        ]);

        if (!$page || isset($page['missing']) || isset($page['invalid'])) return null;
        if (($page['ns'] ?? null) !== 0) return null;

        return $page['title'];
    }

    /** 出会い点から両側のチェーンを繋いで経路を組み立てる */
    private function buildPath(array $fwdParents, array $bwdChildren, string $meetingNode): array
    {
        // start → ... → meetingNode
        $forward = [];
        $cur = $meetingNode;
        while ($cur !== null) {
            array_unshift($forward, $cur);
            $cur = $fwdParents[$cur] ?? null;
        }
        // meetingNode の次 → ... → goal
        $backward = [];
        $cur = $bwdChildren[$meetingNode] ?? null;
        while ($cur !== null) {
            $backward[] = $cur;
            $cur = $bwdChildren[$cur] ?? null;
        }
        $path = array_merge($forward, $backward);
        return ['path' => $path, 'clicks' => count($path) - 1];
    }
}