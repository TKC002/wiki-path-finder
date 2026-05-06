<?php

namespace App\Http\Controllers;

use App\Repositories\SearchHistoryRepository;
use App\Services\WikipediaApiClient;
use App\Services\WikipediaPathFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PathFinderController
{
    public function index(): View
    {
        return view('finder');
    }

    /** SSE版: 進捗をリアルタイムに流す */
    public function stream(Request $request): StreamedResponse
    {
        $startUrl = (string) $request->query('start_url', '');
        $goalUrl = (string) $request->query('goal_url', '');
        $depth = (int) $request->query('depth', config('finder.default_max_depth_per_side', 3));

        return response()->stream(function () use ($startUrl, $goalUrl, $depth) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');

            // ★ ユーザーがブラウザを閉じたら即停止
            ignore_user_abort(false);

            // ★ 探索全体のタイムアウト(秒)
            $hardTimeoutSec = 540; // PHPのset_time_limitより少し短く
            set_time_limit(0);
            $absoluteDeadline = microtime(true) + $hardTimeoutSec;

            // ★ シャットダウンハンドラ: 異常終了時にも error/done を送る
            $sendShutdown = function () use (&$alreadySentDone) {
                if (!empty($alreadySentDone))
                    return;
                echo "event: error\n";
                echo 'data: ' . json_encode(
                    ['message' => '探索が予期せず終了しました(タイムアウトまたは接続切断)。'],
                    JSON_UNESCAPED_UNICODE
                ) . "\n\n";
                echo "event: done\n";
                echo "data: {}\n\n";
                @ob_flush();
                @flush();
            };
            $alreadySentDone = false;
            register_shutdown_function($sendShutdown);

            $send = function (string $event, array $data) use (&$alreadySentDone) {
                if ($event === 'done') {
                    $alreadySentDone = true;
                }
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                @ob_flush();
                @flush();

                // ★ クライアント切断検知
                if (connection_aborted()) {
                    throw new \RuntimeException('Client disconnected');
                }
            };

            $start = WikipediaPathFinder::parseUrl($startUrl);
            $goal = WikipediaPathFinder::parseUrl($goalUrl);

            if (!$start || !$goal) {
                $send('error', ['message' => '有効なWikipediaのURLを入力してください。']);
                $send('done', []);
                return;
            }
            if ($start['lang'] !== $goal['lang']) {
                $send('error', ['message' => 'スタートとゴールは同じ言語版である必要があります。']);
                $send('done', []);
                return;
            }

            $send('connected', [
                'start' => $start,
                'goal' => $goal,
                'depth' => $depth,
            ]);

            $startedAt = microtime(true);

            try {
                $finder = new WikipediaPathFinder($start['lang'], $depth);
                $finder->setProgressCallback(function (string $event, array $payload) use ($send, $absoluteDeadline) {
                    if (microtime(true) > $absoluteDeadline) {
                        throw new \RuntimeException(sprintf(
                            '探索がタイムアウトしました(%d秒)。深さを下げて再試行してください。',
                            (int) (microtime(true) - $absoluteDeadline + 540)
                        ));
                    }
                    $send($event, $payload);
                });

                $result = $finder->findPath($start['title'], $goal['title']);

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                if (isset($result['error'])) {
                    // 失敗も履歴に残す(found=false で記録)
                    $this->recordFailedSearch(
                        $start['title'],
                        $goal['title'],
                        $start['lang'],
                        $durationMs,
                        $finder->getApiCalls(),
                        $finder->getVisitedCount(),
                        $finder->getMaxDepthPerSide()
                    );

                    $send('error', ['message' => $result['error']]);
                } else {
                    // 履歴に記録
                    $historyId = $this->recordSuccessfulSearch(
                        $result['path_ids'][0],
                        end($result['path_ids']),
                        $result['path_ids'],
                        $durationMs,
                        $finder->getApiCalls(),
                        $finder->getVisitedCount(),
                        $finder->getMaxDepthPerSide()
                    );

                    $path = array_map(fn($t) => [
                        'title' => $t,
                        'url' => $finder->titleToUrl($t),
                    ], $result['path']);

                    $send('result', [
                        'clicks' => $result['clicks'],
                        'path' => $path,
                        'history_id' => $historyId,
                        'duration_ms' => $durationMs,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('[finder] stream exception', [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]);

                // タイムアウトやクライアント切断も履歴に残す
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->recordFailedSearch(
                    $start['title'],
                    $goal['title'],
                    $start['lang'],
                    $durationMs,
                    isset($finder) ? $finder->getApiCalls() : 0,
                    isset($finder) ? $finder->getVisitedCount() : 0,
                    isset($finder) ? $finder->getMaxDepthPerSide() : $depth
                );

                $send('error', ['message' => sprintf('[%s] %s', class_basename($e), $e->getMessage())]);
            }

            $send('done', []);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** Wikipediaのタイトル候補を返す */
    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $lang = (string) $request->query('lang', 'ja');
        if (!preg_match('/^[a-z-]{2,12}$/', $lang)) {
            $lang = 'ja';
        }
        if ($q === '') {
            return response()->json(['suggestions' => []]);
        }

        try {
            $api = new WikipediaApiClient($lang);
            $suggestions = $api->suggest($q, 10);
            return response()->json(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            Log::warning('[finder] suggest failed', ['message' => $e->getMessage(), 'q' => $q]);
            return response()->json(['suggestions' => []]);
        }
    }

    // -----------------------------------------------------------
    // 履歴記録ヘルパー
    // -----------------------------------------------------------

    private function recordSuccessfulSearch(
        int $startId,
        int $goalId,
        array $pathIds,
        int $durationMs,
        int $apiCalls,
        int $visitedCount,
        int $maxDepthPerSide
    ): int {
        $repo = new SearchHistoryRepository();
        return $repo->record(
            $startId,
            $goalId,
            $pathIds,
            true,
            $durationMs,
            $apiCalls,
            $visitedCount,
            $maxDepthPerSide
        );
    }

    private function recordFailedSearch(
        string $startTitle,
        string $goalTitle,
        string $lang,
        int $durationMs,
        int $apiCalls,
        int $visitedCount,
        int $maxDepthPerSide
    ): void {
        try {
            $pageRepo = new \App\Repositories\PageRepository();
            $idMap = $pageRepo->ensurePages([$startTitle, $goalTitle]);
            $startId = $idMap[$startTitle] ?? null;
            $goalId = $idMap[$goalTitle] ?? null;
            if ($startId === null || $goalId === null)
                return;

            $repo = new SearchHistoryRepository();
            $repo->record(
                $startId,
                $goalId,
                [],
                false,
                $durationMs,
                $apiCalls,
                $visitedCount,
                $maxDepthPerSide
            );
        } catch (\Throwable $e) {
            Log::warning('[finder] failed to record failed search', ['message' => $e->getMessage()]);
        }
    }
}