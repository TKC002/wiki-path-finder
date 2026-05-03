<?php

namespace App\Services;

use App\Repositories\LinkRepository;
use App\Repositories\PageRepository;
use Closure;

/**
 * リンク取得のキャッシュ層。
 * DB(キャッシュ)とWikipedia API(オリジン)を仲介する。
 */
class LinkProvider
{
    private string $lang;
    private WikipediaApiClient $api;
    private PageRepository $pageRepo;
    private LinkRepository $linkRepo;

    /** 進捗通知コールバック */
    private ?Closure $onProgress = null;

    public function __construct(
        string $lang,
        WikipediaApiClient $api,
        PageRepository $pageRepo,
        LinkRepository $linkRepo
    ) {
        $this->lang     = $lang;
        $this->api      = $api;
        $this->pageRepo = $pageRepo;
        $this->linkRepo = $linkRepo;
    }

    public function setProgressCallback(Closure $cb): void
    {
        $this->onProgress = $cb;
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($event, $payload);
        }
    }

    /**
     * 複数ページの「出ていくリンク先タイトル」を取得する(キャッシュ判定込み)
     *
     * @param string[] $titles  ソースページのタイトル配列
     * @return array  title => [target_title, ...] の連想配列
     */
    public function getOutgoingLinks(array $titles): array
    {
        return $this->getLinks($titles, /* outgoing */ true);
    }

    /**
     * 複数ページの「入ってくるリンク元タイトル」を取得する
     *
     * 注意: linkshere は変化が大きく、巨大ページで取得コストも巨大なので、
     * 現時点ではキャッシュせず常に Wikipedia API から取得する。
     */
    public function getIncomingLinks(array $titles): array
    {
        if (empty($titles)) return [];

        $result = array_fill_keys($titles, []);
        $this->emit('fetching_incoming', ['count' => count($titles)]);

        foreach ($titles as $title) {
            $result[$title] = $this->api->getAllIncomingLinks($title);
        }

        return $result;
    }

    // -----------------------------------------------------------
    // 内部実装
    // -----------------------------------------------------------

    /**
     * 複数ページのリンクを取得する本体(現状は outgoing のみキャッシュする)
     */
    private function getLinks(array $titles, bool $outgoing): array
    {
        if (empty($titles)) return [];

        $titles = array_values(array_unique($titles));

        // 1. ページIDを確保(なければ作る)
        $idMap = $this->pageRepo->ensurePages($titles);  // title => id

        // 2. 鮮度判定
        $pageIds = array_values($idMap);
        $freshnessMap = $this->pageRepo->getFreshnessMap($pageIds);  // id => freshness

        // 3. 分類
        $classified = $this->classifyByFreshness($idMap, $freshnessMap);

        $this->emit('cache_classify', [
            'fresh'   => count($classified['fresh']),
            'check'   => count($classified['check']),
            'stale'   => count($classified['stale']),
            'missing' => count($classified['missing']),
        ]);

        // 4. 'check' グループは touched で確認、結果に応じて再分類
        if (!empty($classified['check'])) {
            $this->verifyAndRedistribute($classified);
        }

        // 5. APIから取得すべきもの = stale + missing(verifyの結果staleに移ったもの含む)
        $needFetch = array_merge($classified['stale'], $classified['missing']);

        if (!empty($needFetch)) {
            $this->fetchAndStoreOutgoing($needFetch);
        }

        // 6. 全ページのIDが確定したので、DBから一括でリンクを取得
        //    (ここで stale だったページのリンクは既に新しくなっている)
        $allIds = array_values($idMap);
        $linkIdsByPageId = $this->linkRepo->getOutgoingTargetIds($allIds);

        // 7. ID → タイトル変換
        // 全ターゲットIDを集めて1クエリで解決
        $allTargetIds = [];
        foreach ($linkIdsByPageId as $targetIds) {
            foreach ($targetIds as $tid) $allTargetIds[$tid] = true;
        }
        $titleMap = $this->pageRepo->getTitleMap(array_keys($allTargetIds));

        // 8. 結果を組み立てる: source title => [target title, ...]
        $result = [];
        foreach ($idMap as $title => $id) {
            $targetTitles = [];
            foreach ($linkIdsByPageId[$id] ?? [] as $tid) {
                if (isset($titleMap[$tid])) {
                    $targetTitles[] = $titleMap[$tid];
                }
            }
            $result[$title] = $targetTitles;
        }
        return $result;
    }

    /**
     * 鮮度ごとに [title => id] を分類
     * 戻り値: ['fresh' => [...], 'check' => [...], 'stale' => [...], 'missing' => [...]]
     * 各値も title => id の連想配列
     */
    private function classifyByFreshness(array $idMap, array $freshnessMap): array
    {
        $buckets = ['fresh' => [], 'check' => [], 'stale' => [], 'missing' => []];
        foreach ($idMap as $title => $id) {
            $f = $freshnessMap[$id] ?? 'missing';
            $buckets[$f][$title] = $id;
        }
        return $buckets;
    }

    /**
     * 'check' グループに対して touched を確認し、
     * - Wikipedia 側が不変 → fresh扱い + fetched_at だけ更新
     * - Wikipedia 側が更新あり → stale扱い に降格(後続で再取得)
     */
    private function verifyAndRedistribute(array &$classified): void
    {
        $checkTitles = array_keys($classified['check']);
        $this->emit('cache_check_touched', ['count' => count($checkTitles)]);

        $touchedMap = $this->api->getTouchedTimes($checkTitles);  // title => Carbon|null

        foreach ($classified['check'] as $title => $id) {
            $newTouched = $touchedMap[$title] ?? null;
            $oldTouched = \App\Models\PageMeta::where('page_id', $id)->value('wiki_touched_at');

            // 比較: Carbon が同じか or DB側が新しい
            $changed = false;
            if ($newTouched === null) {
                // touched取れなかったら念のため再取得扱い
                $changed = true;
            } elseif ($oldTouched === null) {
                $changed = true;
            } else {
                // Carbon と DateTime/string の混在に注意
                $oldCarbon = \Carbon\Carbon::parse($oldTouched);
                $changed = $newTouched->gt($oldCarbon);
            }

            if ($changed) {
                $classified['stale'][$title] = $id;
            } else {
                // 不変だったので fetched_at だけ更新して fresh 扱い
                $this->pageRepo->refreshFetchedAt($id);
                $classified['fresh'][$title] = $id;
            }
        }
        // 元のcheckはクリア
        $classified['check'] = [];
    }

    /**
     * 指定ページの全リンクを Wikipedia から取得して DB に保存する
     *
     * @param array $titleIdMap  title => id の連想配列(取得対象)
     */
    private function fetchAndStoreOutgoing(array $titleIdMap): void
    {
        $titles = array_keys($titleIdMap);
        $total  = count($titles);
        if ($total === 0) return;

        $this->emit('fetching_links', ['count' => $total]);

        // touched時刻も同時に取りたい(再取得時のwiki_touched_at記録のため)
        $touchedMap = $this->api->getTouchedTimes($titles);

        $i = 0;
        foreach ($titles as $title) {
            $i++;
            $this->emit('fetching_progress', [
                'current' => $i,
                'total'   => $total,
                'title'   => $title,
            ]);

            $sourceId    = $titleIdMap[$title];
            $linkTitles  = $this->api->getAllOutgoingLinks($title);
            $linkTitles  = array_values(array_unique($linkTitles));

            // リンク先ページのIDも確保(なければ作る)
            $targetIdMap = empty($linkTitles)
                ? []
                : $this->pageRepo->ensurePages($linkTitles);

            $targetIds = array_values($targetIdMap);

            // links テーブルを置き換え
            $this->linkRepo->replaceOutgoingLinks($sourceId, $targetIds);

            // page_meta を更新
            $this->pageRepo->upsertMeta(
                $sourceId,
                $touchedMap[$title] ?? null,
                count($targetIds)
            );
        }
    }
}