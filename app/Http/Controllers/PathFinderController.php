<?php

namespace App\Http\Controllers;

use App\Services\WikipediaPathFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

// ★ extends Controller を外す(Laravel 11/12 対策)
class PathFinderController
{
    public function index(): View
    {
        return view('finder');
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
}