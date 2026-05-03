<?php

namespace App\Repositories;

use App\Models\Link;
use Illuminate\Support\Facades\DB;

class LinkRepository
{
    /**
     * 指定ページIDが「リンク元」となっているリンクの target_id 配列を取得
     * 戻り値: source_id => [target_id, target_id, ...]
     */
    public function getOutgoingTargetIds(array $sourceIds): array
    {
        if (empty($sourceIds)) return [];

        $rows = Link::whereIn('source_id', $sourceIds)
            ->select('source_id', 'target_id')
            ->get();

        $result = array_fill_keys($sourceIds, []);
        foreach ($rows as $row) {
            $result[$row->source_id][] = $row->target_id;
        }
        return $result;
    }

    /**
     * 指定ページIDが「リンク先」となっているリンクの source_id 配列を取得
     * 戻り値: target_id => [source_id, source_id, ...]
     */
    public function getIncomingSourceIds(array $targetIds): array
    {
        if (empty($targetIds)) return [];

        $rows = Link::whereIn('target_id', $targetIds)
            ->select('source_id', 'target_id')
            ->get();

        $result = array_fill_keys($targetIds, []);
        foreach ($rows as $row) {
            $result[$row->target_id][] = $row->source_id;
        }
        return $result;
    }

    /**
     * あるページの全 outgoing リンクを置き換える(古いものを削除して新しいものを挿入)
     * トランザクション内で実行される
     */
    public function replaceOutgoingLinks(int $sourceId, array $targetIds): void
    {
        DB::transaction(function () use ($sourceId, $targetIds) {
            // 既存削除
            Link::where('source_id', $sourceId)->delete();

            if (empty($targetIds)) return;

            // 新規挿入(チャンク分割で大量データに対応)
            $rows = array_map(
                fn ($tid) => ['source_id' => $sourceId, 'target_id' => $tid],
                array_unique($targetIds)
            );
            foreach (array_chunk($rows, 1000) as $chunk) {
                Link::insertOrIgnore($chunk);
            }
        });
    }

    /**
     * あるページへの incoming リンクを追加する(既存は削除しない)。
     *
     * forward 側が先に保存した (source, target) レコードを壊さないように、
     * insertOrIgnore で追加のみ行う。BFS的にはリンクが多い分には問題ない。
     */
    public function addIncomingLinks(int $targetId, array $sourceIds): void
    {
        if (empty($sourceIds)) return;

        $rows = array_map(
            fn ($sid) => ['source_id' => $sid, 'target_id' => $targetId],
            array_values(array_unique($sourceIds))
        );
        foreach (array_chunk($rows, 1000) as $chunk) {
            Link::insertOrIgnore($chunk);
        }
    }

    /** あるページから出ている全リンクを削除 */
    public function deleteOutgoingLinks(int $sourceId): void
    {
        Link::where('source_id', $sourceId)->delete();
    }

    /** 統計用: リンク総数 */
    public function totalCount(): int
    {
        return Link::count();
    }
}