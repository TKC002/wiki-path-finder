<?php

namespace App\Repositories;

use App\Models\SearchHistory;
use App\Models\SearchPathStep;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SearchHistoryRepository
{
    /**
     * 探索結果を記録する(append only)
     *
     * @param int   $startId
     * @param int   $goalId
     * @param array $pathPageIds  経路の page_id 配列(start から goal の順)
     * @param bool  $found
     * @param int   $durationMs
     * @param int   $apiCalls
     * @param int   $visitedCount
     * @param int   $maxDepthPerSide
     * @return int  作成された history_id
     */
    public function record(
        int $startId,
        int $goalId,
        array $pathPageIds,
        bool $found,
        int $durationMs,
        int $apiCalls,
        int $visitedCount,
        int $maxDepthPerSide
    ): int {
        return DB::transaction(function () use (
            $startId, $goalId, $pathPageIds, $found,
            $durationMs, $apiCalls, $visitedCount, $maxDepthPerSide
        ) {
            $clicks = $found ? max(0, count($pathPageIds) - 1) : null;

            $history = SearchHistory::create([
                'start_id'           => $startId,
                'goal_id'            => $goalId,
                'clicks'             => $clicks,
                'found'              => $found,
                'duration_ms'        => $durationMs,
                'api_calls'          => $apiCalls,
                'visited_count'      => $visitedCount,
                'max_depth_per_side' => $maxDepthPerSide,
                'searched_at'        => Carbon::now(),
            ]);

            if ($found && !empty($pathPageIds)) {
                $rows = [];
                foreach ($pathPageIds as $i => $pid) {
                    $rows[] = [
                        'history_id' => $history->id,
                        'step_index' => $i,
                        'page_id'    => $pid,
                    ];
                }
                SearchPathStep::insert($rows);
            }

            return $history->id;
        });
    }
}