<?php

namespace App\Services;

use App\Repositories\LinkRepository;
use App\Repositories\PageRepository;
use Closure;

/**
 * Wikipedia 双方向BFS探索エンジン。
 *
 * リンク取得は LinkProvider に委譲することで、本クラスは
 * 純粋にBFSアルゴリズムだけに集中する。
 */
class WikipediaPathFinder
{
    private const POOL_SIZE = 20;

    private string $lang;
    private int $maxDepthPerSide;

    private WikipediaApiClient $api;
    private LinkProvider $linkProvider;
    private PageRepository $pageRepo;

    /** 進捗通知用のコールバック */
    private ?Closure $onProgress = null;

    /** 累計探索ノード数 */
    private int $visitedCount = 0;

    public function __construct(
        string $lang,
        ?int $maxDepthPerSide = null
    ) {
        $this->lang = $lang;

        // 深さの正規化(設定の min/max にクランプ)
        $default = (int) config('finder.default_max_depth_per_side', 3);
        $min     = (int) config('finder.min_depth_per_side', 1);
        $max     = (int) config('finder.max_depth_per_side', 5);
        $depth   = $maxDepthPerSide ?? $default;
        $this->maxDepthPerSide = max($min, min($max, $depth));

        // 依存性は内部で構築(本来はDI推奨だが、簡潔さ優先)
        $this->api          = new WikipediaApiClient($lang);
        $this->pageRepo     = new PageRepository();
        $this->linkProvider = new LinkProvider(
            $lang, $this->api, $this->pageRepo, new LinkRepository()
        );
    }

    public function setProgressCallback(Closure $cb): void
    {
        $this->onProgress = $cb;
        // LinkProvider にもパイプスルー(キャッシュ判定の進捗が見える)
        $this->linkProvider->setProgressCallback(function (string $e, array $p) {
            $this->emit($e, $p);
        });
    }

    public function getMaxDepthPerSide(): int
    {
        return $this->maxDepthPerSide;
    }

    public function getApiCalls(): int
    {
        return $this->api->getApiCalls();
    }

    public function getVisitedCount(): int
    {
        return $this->visitedCount;
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($event, $payload + [
                'api_calls'     => $this->api->getApiCalls(),
                'visited_count' => $this->visitedCount,
            ]);
        }
    }

    // -----------------------------------------------------------
    // ユーティリティ(URLパース)
    // -----------------------------------------------------------

    public static function parseUrl(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('~^https?://([a-z-]+)\.wikipedia\.org/wiki/([^?#\s]+)~u', $url, $m)) {
            return null;
        }
        $title = str_replace('_', ' ', rawurldecode($m[2]));
        return ['lang' => $m[1], 'title' => $title];
    }

    public function titleToUrl(string $title): string
    {
        $path = rawurlencode(str_replace(' ', '_', $title));
        $path = str_replace(['%2F', '%3A'], ['/', ':'], $path);
        return "https://{$this->lang}.wikipedia.org/wiki/{$path}";
    }

    // -----------------------------------------------------------
    // 探索本体
    // -----------------------------------------------------------

    /**
     * 双方向BFSで最短経路を探索。
     *
     * 戻り値:
     *   成功時 ['path' => [title, ...], 'path_ids' => [id, ...], 'clicks' => N]
     *   失敗時 ['error' => '...']
     */
    public function findPath(string $startTitle, string $goalTitle): array
    {
        $this->emit('normalize', ['title' => $startTitle, 'role' => 'start']);
        $start = $this->api->normalizeTitle($startTitle);
        if ($start === null) {
            return ['error' => "スタートページ「{$startTitle}」が見つかりません。"];
        }

        $this->emit('normalize', ['title' => $goalTitle, 'role' => 'goal']);
        $goal = $this->api->normalizeTitle($goalTitle);
        if ($goal === null) {
            return ['error' => "ゴールページ「{$goalTitle}」が見つかりません。"];
        }

        if ($start === $goal) {
            $idMap = $this->pageRepo->ensurePages([$start]);
            return [
                'path'     => [$start],
                'path_ids' => [$idMap[$start]],
                'clicks'   => 0,
            ];
        }

        // 訪問済み: タイトル => 親(または子)タイトル
        $fwdParents  = [$start => null];
        $bwdChildren = [$goal  => null];
        $this->visitedCount = 2;

        $fwdFrontier = [$start];
        $bwdFrontier = [$goal];
        $fwdDepth = 0;
        $bwdDepth = 0;

        $this->emit('search_start', [
            'start' => $start,
            'goal'  => $goal,
            'max_depth_per_side' => $this->maxDepthPerSide,
            'max_depth_total'    => $this->maxDepthPerSide * 2,
        ]);

        while ($fwdDepth < $this->maxDepthPerSide || $bwdDepth < $this->maxDepthPerSide) {
            $expandForward = (count($fwdFrontier) <= count($bwdFrontier))
                ? ($fwdDepth < $this->maxDepthPerSide)
                : ($bwdDepth >= $this->maxDepthPerSide);

            if ($expandForward) {
                $this->emit('layer_start', [
                    'direction'     => 'forward',
                    'depth'         => $fwdDepth + 1,
                    'frontier_size' => count($fwdFrontier),
                    'total_depth'   => $fwdDepth + $bwdDepth + 1,
                ]);
                $meeting = $this->expandForward($fwdFrontier, $fwdParents, $bwdChildren);
                $fwdDepth++;
                $this->emit('layer_end', [
                    'direction'         => 'forward',
                    'depth'             => $fwdDepth,
                    'new_frontier_size' => count($meeting['frontier']),
                ]);
                if ($meeting['found']) {
                    $this->emit('meeting', ['node' => $meeting['node']]);
                    return $this->buildResult($fwdParents, $bwdChildren, $meeting['node']);
                }
                $fwdFrontier = $meeting['frontier'];
            } else {
                $this->emit('layer_start', [
                    'direction'     => 'backward',
                    'depth'         => $bwdDepth + 1,
                    'frontier_size' => count($bwdFrontier),
                    'total_depth'   => $fwdDepth + $bwdDepth + 1,
                ]);
                $meeting = $this->expandBackward($bwdFrontier, $bwdChildren, $fwdParents);
                $bwdDepth++;
                $this->emit('layer_end', [
                    'direction'         => 'backward',
                    'depth'             => $bwdDepth,
                    'new_frontier_size' => count($meeting['frontier']),
                ]);
                if ($meeting['found']) {
                    $this->emit('meeting', ['node' => $meeting['node']]);
                    return $this->buildResult($fwdParents, $bwdChildren, $meeting['node']);
                }
                $bwdFrontier = $meeting['frontier'];
            }

            if (empty($fwdFrontier) && empty($bwdFrontier)) {
                break;
            }
        }

        return ['error' => '探索範囲内(最大' . ($this->maxDepthPerSide * 2) . 'クリック)では経路が見つかりませんでした。'];
    }

    /** forward方向に1階層展開 */
    private function expandForward(array $frontier, array &$fwdParents, array $bwdChildren): array
    {
        $frontier = array_values(array_unique($frontier));
        $newFrontier = [];

        $linkMap = $this->linkProvider->getOutgoingLinks($frontier);

        foreach ($linkMap as $source => $links) {
            foreach ($links as $link) {
                if (array_key_exists($link, $fwdParents)) continue;
                $fwdParents[$link] = $source;
                $this->visitedCount++;

                if (array_key_exists($link, $bwdChildren)) {
                    return ['found' => true, 'node' => $link, 'frontier' => []];
                }
                $newFrontier[] = $link;
            }
        }
        return ['found' => false, 'node' => null, 'frontier' => $newFrontier];
    }

    /** backward方向に1階層展開 */
    private function expandBackward(array $frontier, array &$bwdChildren, array $fwdParents): array
    {
        $frontier = array_values(array_unique($frontier));
        $newFrontier = [];

        $linkMap = $this->linkProvider->getIncomingLinks($frontier);

        foreach ($linkMap as $target => $incomingPages) {
            foreach ($incomingPages as $page) {
                if (array_key_exists($page, $bwdChildren)) continue;
                $bwdChildren[$page] = $target;
                $this->visitedCount++;

                if (array_key_exists($page, $fwdParents)) {
                    return ['found' => true, 'node' => $page, 'frontier' => []];
                }
                $newFrontier[] = $page;
            }
        }
        return ['found' => false, 'node' => null, 'frontier' => $newFrontier];
    }

    /**
     * 出会い点から経路を構築し、結果を組み立てる(タイトル配列とID配列を両方返す)
     */
    private function buildResult(array $fwdParents, array $bwdChildren, string $meetingNode): array
    {
        $forward = [];
        $cur = $meetingNode;
        while ($cur !== null) {
            array_unshift($forward, $cur);
            $cur = $fwdParents[$cur] ?? null;
        }
        $backward = [];
        $cur = $bwdChildren[$meetingNode] ?? null;
        while ($cur !== null) {
            $backward[] = $cur;
            $cur = $bwdChildren[$cur] ?? null;
        }
        $path = array_merge($forward, $backward);

        // 経路上の全ページのIDを確保(履歴記録用)
        $idMap = $this->pageRepo->ensurePages($path);
        $pathIds = array_map(fn ($t) => $idMap[$t] ?? null, $path);

        return [
            'path'     => $path,
            'path_ids' => $pathIds,
            'clicks'   => count($path) - 1,
        ];
    }
}