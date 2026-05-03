<?php

namespace App\Repositories;

use App\Models\Page;
use App\Models\PageMeta;
use Carbon\Carbon;

class PageRepository
{
    /**
     * 指定タイトルのページIDを取得。なければ作成する(upsert)。
     * 戻り値: title => id の連想配列
     */
    public function ensurePages(array $titles): array
    {
        if (empty($titles)) {
            return [];
        }

        $titles = array_values(array_unique($titles));

        // 既存を一括取得
        $existing = Page::whereIn('title', $titles)
            ->pluck('id', 'title')
            ->all();

        // 不足分を作成
        $missing = array_diff($titles, array_keys($existing));
        if (!empty($missing)) {
            $rows = array_map(fn ($t) => ['title' => $t], $missing);
            // insertOrIgnore: 既存があってもエラーにしない(レース条件対策)
            Page::insertOrIgnore($rows);

            // 作成したものを取り直す
            $reread = Page::whereIn('title', $missing)
                ->pluck('id', 'title')
                ->all();
            $existing = $existing + $reread;
        }

        return $existing;
    }

    /** タイトル → ID 変換(なければnull) */
    public function findIdByTitle(string $title): ?int
    {
        return Page::where('title', $title)->value('id');
    }

    /** ID → タイトル変換(なければnull) */
    public function findTitleById(int $id): ?string
    {
        return Page::where('id', $id)->value('title');
    }

    /** ID 配列 → ID=>タイトル の連想配列 */
    public function getTitleMap(array $ids): array
    {
        if (empty($ids)) return [];
        return Page::whereIn('id', $ids)
            ->pluck('title', 'id')
            ->all();
    }

    /**
     * 指定ページIDのキャッシュ鮮度を判定する。
     *
     * 戻り値: 'fresh' | 'check' | 'stale' | 'missing'
     *   - fresh   : 24h以内、即DBから使える
     *   - check   : 24h〜7d、touched確認が必要
     *   - stale   : 7d超、強制再取得
     *   - missing : page_meta に行がない
     */
    public function getFreshness(int $pageId): string
    {
        $meta = PageMeta::where('page_id', $pageId)->first();
        if (!$meta) {
            return 'missing';
        }

        $hoursOld = $meta->fetched_at->diffInHours(Carbon::now());
        $freshTtl = config('finder.fresh_ttl_hours', 24);
        $maxTtl   = config('finder.max_ttl_days', 7) * 24;

        if ($hoursOld < $freshTtl) return 'fresh';
        if ($hoursOld < $maxTtl)   return 'check';
        return 'stale';
    }

    /** 複数ページの鮮度を一括判定 (id => freshness) */
    public function getFreshnessMap(array $pageIds): array
    {
        if (empty($pageIds)) return [];

        $metas = PageMeta::whereIn('page_id', $pageIds)->get()->keyBy('page_id');

        $freshTtl = config('finder.fresh_ttl_hours', 24);
        $maxTtl   = config('finder.max_ttl_days', 7) * 24;
        $now      = Carbon::now();

        $result = [];
        foreach ($pageIds as $id) {
            $meta = $metas->get($id);
            if (!$meta) {
                $result[$id] = 'missing';
                continue;
            }
            $hoursOld = $meta->fetched_at->diffInHours($now);
            if ($hoursOld < $freshTtl) {
                $result[$id] = 'fresh';
            } elseif ($hoursOld < $maxTtl) {
                $result[$id] = 'check';
            } else {
                $result[$id] = 'stale';
            }
        }
        return $result;
    }

    /** メタ情報を upsert(リンク再取得後に呼ぶ) */
    public function upsertMeta(int $pageId, ?Carbon $wikiTouchedAt, int $linkCount): void
    {
        PageMeta::updateOrCreate(
            ['page_id' => $pageId],
            [
                'wiki_touched_at' => $wikiTouchedAt,
                'fetched_at'      => Carbon::now(),
                'link_count'      => $linkCount,
            ]
        );
    }

    /** fetched_at だけ更新(touched確認の結果Wikipedia側も不変だった時) */
    public function refreshFetchedAt(int $pageId): void
    {
        PageMeta::where('page_id', $pageId)->update([
            'fetched_at' => Carbon::now(),
        ]);
    }
}