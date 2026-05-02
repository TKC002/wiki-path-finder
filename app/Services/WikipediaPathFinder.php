<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WikipediaPathFinder
{
    private const MAX_DEPTH_PER_SIDE = 3;
    private const MAX_FRONTIER       = 200;
    private const POOL_SIZE          = 20;
    private const TIMEOUT            = 15;

    private string $lang;
    private string $apiUrl;

    /** 進捗通知用のコールバック ($event, array $payload) */
    private ?Closure $onProgress = null;

    /** 累計APIリクエスト回数 */
    private int $apiCalls = 0;
    /** 累計探索ノード数(訪問済みに加わった数) */
    private int $visitedCount = 0;

    public function __construct(string $lang = 'ja')
    {
        $this->lang   = $lang;
        $this->apiUrl = "https://{$lang}.wikipedia.org/w/api.php";
    }

    public function setProgressCallback(Closure $cb): void
    {
        $this->onProgress = $cb;
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($event, $payload + [
                'api_calls'     => $this->apiCalls,
                'visited_count' => $this->visitedCount,
            ]);
        }
    }

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

    public function findPath(string $startTitle, string $goalTitle): array
    {
        $this->emit('normalize', ['title' => $startTitle, 'role' => 'start']);
        $start = $this->normalizeTitle($startTitle);
        if ($start === null) {
            return ['error' => "スタートページ「{$startTitle}」が見つかりません。"];
        }

        $this->emit('normalize', ['title' => $goalTitle, 'role' => 'goal']);
        $goal = $this->normalizeTitle($goalTitle);
        if ($goal === null) {
            return ['error' => "ゴールページ「{$goalTitle}」が見つかりません。"];
        }
        if ($start === $goal) {
            return ['path' => [$start], 'clicks' => 0];
        }

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
            'max_depth_total' => self::MAX_DEPTH_PER_SIDE * 2,
        ]);

        while ($fwdDepth < self::MAX_DEPTH_PER_SIDE || $bwdDepth < self::MAX_DEPTH_PER_SIDE) {
            $expandForward = (count($fwdFrontier) <= count($bwdFrontier))
                ? ($fwdDepth < self::MAX_DEPTH_PER_SIDE)
                : ($bwdDepth >= self::MAX_DEPTH_PER_SIDE);

            if ($expandForward) {
                $this->emit('layer_start', [
                    'direction'      => 'forward',
                    'depth'          => $fwdDepth + 1,
                    'frontier_size'  => count($fwdFrontier),
                    'total_depth'    => $fwdDepth + $bwdDepth + 1,
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
                    return $this->buildPath($fwdParents, $bwdChildren, $meeting['node']);
                }
                $fwdFrontier = $meeting['frontier'];
            } else {
                $this->emit('layer_start', [
                    'direction'      => 'backward',
                    'depth'          => $bwdDepth + 1,
                    'frontier_size'  => count($bwdFrontier),
                    'total_depth'    => $fwdDepth + $bwdDepth + 1,
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

    private function expandForward(array $frontier, array &$fwdParents, array $bwdChildren): array
    {
        $frontier = array_slice(array_values(array_unique($frontier)), 0, self::MAX_FRONTIER);
        $newFrontier = [];

        foreach ($this->getOutgoingLinksBatch($frontier) as $source => $links) {
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

    private function expandBackward(array $frontier, array &$bwdChildren, array $fwdParents): array
    {
        $frontier = array_slice(array_values(array_unique($frontier)), 0, self::MAX_FRONTIER);
        $newFrontier = [];

        foreach ($this->getIncomingLinksBatch($frontier) as $target => $incomingPages) {
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

    private function getOutgoingLinksBatch(array $titles): array
    {
        return $this->batchRequest($titles, [
            'prop'        => 'links',
            'pllimit'     => 'max',
            'plnamespace' => 0,
        ], 'links', 'title');
    }

    private function getIncomingLinksBatch(array $titles): array
    {
        return $this->batchRequest($titles, [
            'prop'        => 'linkshere',
            'lhlimit'     => 'max',
            'lhnamespace' => 0,
            'lhshow'      => '!redirect',
        ], 'linkshere', 'title');
    }

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

            $this->apiCalls += count($chunk);

            foreach ($chunk as $i => $title) {
                $r = $responses[$i] ?? null;
                if (!$r instanceof Response || !$r->ok()) continue;

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

            // チャンク完了ごとに進捗を出す
            $this->emit('chunk_done', [
                'chunk_size' => count($chunk),
            ]);
        }
        return $results;
    }

    private function normalizeTitle(string $title): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'WikiPathFinder/1.0 (Laravel demo)'])
                ->timeout(self::TIMEOUT)
                ->get($this->apiUrl, [
                    'action'        => 'query',
                    'titles'        => $title,
                    'redirects'     => 1,
                    'format'        => 'json',
                    'formatversion' => 2,
                ]);
        } catch (\Throwable $e) {
            Log::error('[finder] normalizeTitle HTTP error', ['title' => $title, 'message' => $e->getMessage()]);
            throw $e;
        }
        $this->apiCalls++;

        if (!$response->ok()) return null;

        $page = $response->json('query.pages.0');
        if (!$page || isset($page['missing']) || isset($page['invalid'])) return null;
        if (($page['ns'] ?? null) !== 0) return null;

        return $page['title'];
    }

    private function buildPath(array $fwdParents, array $bwdChildren, string $meetingNode): array
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
        return ['path' => $path, 'clicks' => count($path) - 1];
    }
}