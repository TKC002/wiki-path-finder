<?php

namespace App\Http\Controllers;

use App\Models\SearchHistory;
use App\Services\WikipediaPathFinder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoryController
{
    /** 履歴一覧 */
    public function index(Request $request): View
    {
        $perPage = 20;

        $query = SearchHistory::with(['startPage', 'goalPage'])
            ->orderByDesc('searched_at');

        // フィルタ: found / failed / all
        $filter = $request->query('filter', 'all');
        if ($filter === 'found') {
            $query->where('found', true);
        } elseif ($filter === 'failed') {
            $query->where('found', false);
        }

        $histories = $query->paginate($perPage)->appends($request->query());

        return view('history.index', [
            'histories' => $histories,
            'filter'    => $filter,
        ]);
    }

    /** 履歴詳細(経路の可視化) */
    public function show(int $id): View
    {
        $history = SearchHistory::with([
            'startPage',
            'goalPage',
            'steps.page',
        ])->findOrFail($id);

        // 経路をビュー用に整形
        $finder = new WikipediaPathFinder('ja');
        $pathSteps = $history->steps->map(fn ($s) => [
            'title' => $s->page->title,
            'url'   => $finder->titleToUrl($s->page->title),
        ])->all();

        return view('history.show', [
            'history'   => $history,
            'pathSteps' => $pathSteps,
        ]);
    }
}