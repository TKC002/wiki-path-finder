<?php

namespace App\Http\Controllers;

use App\Services\WikipediaPathFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ★ extends Controller を外す(Laravel 11/12 対策)
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
        $goalUrl  = (string) $request->query('goal_url', '');

        return response()->stream(function () use ($startUrl, $goalUrl) {
            // 出力バッファを全部潰す(既存のobレベルを完全に外す)
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            // ストリーミング用の細かいチューニング
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');
            ignore_user_abort(false);
            set_time_limit(300);

            $send = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                @ob_flush();
                @flush();
            };

            $start = WikipediaPathFinder::parseUrl($startUrl);
            $goal  = WikipediaPathFinder::parseUrl($goalUrl);

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

            $send('connected', ['start' => $start, 'goal' => $goal]);

            try {
                $finder = new WikipediaPathFinder($start['lang']);
                $finder->setProgressCallback(function (string $event, array $payload) use ($send) {
                    $send($event, $payload);
                });

                $result = $finder->findPath($start['title'], $goal['title']);

                if (isset($result['error'])) {
                    $send('error', ['message' => $result['error']]);
                } else {
                    $path = array_map(fn ($t) => [
                        'title' => $t,
                        'url'   => $finder->titleToUrl($t),
                    ], $result['path']);
                    $send('result', [
                        'clicks' => $result['clicks'],
                        'path'   => $path,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('[finder] stream exception', ['message' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()]);
                $send('error', ['message' => sprintf('[%s] %s', class_basename($e), $e->getMessage())]);
            }

            $send('done', []);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    public function findPath(Request $request): JsonResponse
    {
        try {
            Log::info('[finder] request received', $request->all());

            $validated = $request->validate([
                'start_url' => ['required', 'string', 'max:2000'],
                'goal_url'  => ['required', 'string', 'max:2000'],
            ]);

            $start = WikipediaPathFinder::parseUrl($validated['start_url']);
            $goal  = WikipediaPathFinder::parseUrl($validated['goal_url']);

            Log::info('[finder] parsed', ['start' => $start, 'goal' => $goal]);

            if (!$start || !$goal) {
                return response()->json([
                    'error' => '有効なWikipediaのURLを入力してください(例: https://ja.wikipedia.org/wiki/日本)',
                ], 422);
            }

            if ($start['lang'] !== $goal['lang']) {
                return response()->json([
                    'error' => 'スタートとゴールは同じ言語版のWikipediaである必要があります。',
                ], 422);
            }

            set_time_limit(300);
            ini_set('memory_limit', '512M');

            $finder = new WikipediaPathFinder($start['lang']);
            $result = $finder->findPath($start['title'], $goal['title']);

            Log::info('[finder] result', ['result' => $result]);

            if (isset($result['error'])) {
                return response()->json(['error' => $result['error']], 404);
            }

            $path = array_map(fn ($title) => [
                'title' => $title,
                'url'   => $finder->titleToUrl($title),
            ], $result['path']);

            return response()->json([
                'clicks' => $result['clicks'],
                'path'   => $path,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('[finder] validation failed', $e->errors());
            return response()->json([
                'error' => '入力値が不正です: ' . collect($e->errors())->flatten()->implode(' / '),
            ], 422);
        } catch (\Throwable $e) {
            // ★ ここで全部キャッチして原因を返す
            Log::error('[finder] exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => sprintf(
                    '[%s] %s (at %s:%d)',
                    class_basename($e),
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                ),
            ], 500);
        }
    }
    /** Wikipediaのタイトル候補を返す(OpenSearch APIをプロキシ) */
    public function suggest(Request $request): JsonResponse
    {
        $q    = trim((string) $request->query('q', ''));
        $lang = (string) $request->query('lang', 'ja');
        if (!preg_match('/^[a-z-]{2,12}$/', $lang)) {
            $lang = 'ja';
        }
        if ($q === '') {
            return response()->json(['suggestions' => []]);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'User-Agent' => 'WikiPathFinder/1.0 (Laravel demo)',
                ])
                ->timeout(5)
                ->get("https://{$lang}.wikipedia.org/w/api.php", [
                    'action'    => 'opensearch',
                    'search'    => $q,
                    'limit'     => 10,
                    'namespace' => 0,
                    'format'    => 'json',
                ]);

            if (!$response->ok()) {
                return response()->json(['suggestions' => []]);
            }

            // OpenSearchの戻り値: [query, [titles...], [descs...], [urls...]]
            $data   = $response->json();
            $titles = $data[1] ?? [];
            $urls   = $data[3] ?? [];

            $suggestions = [];
            foreach ($titles as $i => $title) {
                $suggestions[] = [
                    'title' => $title,
                    'url'   => $urls[$i] ?? '',
                ];
            }
            return response()->json(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            Log::warning('[finder] suggest failed', ['message' => $e->getMessage(), 'q' => $q]);
            return response()->json(['suggestions' => []]);
        }
    }
}