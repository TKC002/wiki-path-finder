<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\PageMeta;
use App\Models\SearchHistory;
use App\Models\SearchPathStep;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StatsController
{
    public function index(): View
    {
        // 全体統計
        $totalSearches    = SearchHistory::count();
        $foundSearches    = SearchHistory::where('found', true)->count();
        $failedSearches   = SearchHistory::where('found', false)->count();
        $totalPages       = Page::count();
        $totalLinks       = DB::table('links')->count();
        $totalCachedPages = PageMeta::count();

        // クリック数分布(成功した探索)
        $clicksDistribution = SearchHistory::where('found', true)
            ->select('clicks', DB::raw('COUNT(*) as cnt'))
            ->groupBy('clicks')
            ->orderBy('clicks')
            ->get()
            ->all();

        // よく中継されるページTOP20(start/goal を除いた中継地点だけ)
        $topHubs = DB::table('search_path_steps as s')
            ->join('search_history as h', 'h.id', '=', 's.history_id')
            ->join('pages as p', 'p.id', '=', 's.page_id')
            ->whereRaw('s.step_index > 0')
            ->whereRaw('s.step_index < h.clicks')
            ->select('p.title', DB::raw("COUNT(DISTINCT CONCAT(h.start_id, ',', h.goal_id)) as appearances"))
            ->groupBy('p.id', 'p.title')
            ->orderByDesc('appearances')
            ->limit(20)
            ->get()
            ->all();

        // 最も時間がかかった探索 TOP10
        $slowest = SearchHistory::with(['startPage', 'goalPage'])
            ->orderByDesc('duration_ms')
            ->limit(10)
            ->get()
            ->all();

        // よく検索されたペアTOP10(同じstart-goalで何度も検索されたもの)
        $popularPairs = SearchHistory::query()
            ->select('start_id', 'goal_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('start_id', 'goal_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('cnt')
            ->limit(10)
            ->with(['startPage', 'goalPage'])
            ->get()
            ->all();

        // リンク数 TOP20 のページ(ハブの可視化、キャッシュ済みの中で)
        $topLinkedPages = PageMeta::query()
            ->join('pages', 'pages.id', '=', 'page_meta.page_id')
            ->select('pages.title', 'page_meta.link_count', 'page_meta.fetched_at')
            ->orderByDesc('page_meta.link_count')
            ->limit(20)
            ->get()
            ->all();

        return view('stats.index', compact(
            'totalSearches',
            'foundSearches',
            'failedSearches',
            'totalPages',
            'totalLinks',
            'totalCachedPages',
            'clicksDistribution',
            'topHubs',
            'slowest',
            'popularPairs',
            'topLinkedPages',
        ));
    }
}