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
        $this->lang = $lang;
        $this->api = $api;
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
        return $this->getLinks($titles, 'outgoing');
    }

    /**
     * 複数ページの「入ってくるリンク元タイトル」を取得する(キャッシュ判定込み)
     *
     * @param string[] $titles  ターゲットページのタイトル配列
     * @return array  title => [source_title, ...] の連想配列
     */
    public function getIncomingLinks(array $titles): array
    {
        return $this->getLinks($titles, 'incoming');
    }

    // -----------------------------------------------------------
    // 内部実装
    // -----------------------------------------------------------

    /**
     * 複数ページのリンクを取得する本体。
     *
     * @param string $direction 'outgoing' | 'incoming'
     */
    private function getLinks(array $titles, string $direction): array
    {
        if (empty($titles))
            return [];

        $titles = array_values(array_unique($titles));

        // 1. ページIDを確保(なければ作る)
        $idMap = $this->pageRepo->ensurePages($titles);  // title => id

        // 2. 鮮度判定
        $pageIds = array_values($idMap);
        $freshnessMap = $direction === 'outgoing'
            ? $this->pageRepo->getFreshnessMap($pageIds)
            : $this->pageRepo->getIncomingFreshnessMap($pageIds);

        // 3. 分類
        $classified = $this->classifyByFreshness($idMap, $freshnessMap);

        $this->emit('cache_classify', [
            'fresh' => count($classified['fresh']),
            'check' => count($classified['check']),
            'missing' => count($classified['missing']),
        ]);

        // 4. 'check' グループの処理(outgoing のみ touched 比較)
        $needFetch = [];
        if (!empty($classified['check'])) {
            if ($direction === 'outgoing') {
                // touched で変更確認 → fresh or needFetch に再分類
                $needFetch = $this->verifyAndRedistribute($classified);
            } else {
                // incoming: touched では判定できないので一律再取得
                $needFetch = $classified['check'];
                $classified['check'] = [];
            }
        }

        // 5. APIから取得すべきもの = needFetch + missing
        $needFetch = array_merge($needFetch, $classified['missing']);

        if (!empty($needFetch)) {
            if ($direction === 'outgoing') {
                $this->fetchAndStoreOutgoing($needFetch);
            } else {
                $this->fetchAndStoreIncoming($needFetch);
            }
        }

        // 6. DBから一括でリンクを取得
        $allIds = array_values($idMap);
        if ($direction === 'outgoing') {
            $linkIdsByPageId = $this->linkRepo->getOutgoingTargetIds($allIds);
        } else {
            $linkIdsByPageId = $this->linkRepo->getIncomingSourceIds($allIds);
        }

        // 7. ID → タイトル変換
        $allLinkedIds = [];
        foreach ($linkIdsByPageId as $linkedIds) {
            foreach ($linkedIds as $lid)
                $allLinkedIds[$lid] = true;
        }
        $titleMap = $this->pageRepo->getTitleMap(array_keys($allLinkedIds));

        // 8. 結果を組み立てる: title => [linked_title, ...]
        $result = [];
        foreach ($idMap as $title => $id) {
            $linkedTitles = [];
            foreach ($linkIdsByPageId[$id] ?? [] as $lid) {
                if (isset($titleMap[$lid])) {
                    $linkedTitles[] = $titleMap[$lid];
                }
            }
            $result[$title] = $linkedTitles;
        }
        return $result;
    }

    /**
     * 鮮度ごとに [title => id] を分類
     */
    private function classifyByFreshness(array $idMap, array $freshnessMap): array
    {
        $buckets = ['fresh' => [], 'check' => [], 'missing' => []];
        foreach ($idMap as $title => $id) {
            $f = $freshnessMap[$id] ?? 'missing';
            $buckets[$f][$title] = $id;
        }
        return $buckets;
    }

    /**
     * 'check' グループに対して touched を確認し、
     * - Wikipedia 側が不変 → fresh扱い + fetched_at だけ更新
     * - Wikipedia 側が更新あり → 再取得が必要(戻り値に含める)
     *
     * @return array  再取得が必要な [title => id]
     */
    private function verifyAndRedistribute(array &$classified): array
    {
        $checkTitles = array_keys($classified['check']);
        $this->emit('cache_check_touched', ['count' => count($checkTitles)]);

        $touchedMap = $this->api->getTouchedTimes($checkTitles);  // title => Carbon|null

        $needFetch = [];

        foreach ($classified['check'] as $title => $id) {
            $newTouched = $touchedMap[$title] ?? null;
            $oldTouched = \App\Models\PageMeta::where('page_id', $id)->value('wiki_touched_at');

            $changed = false;
            if ($newTouched === null) {
                $changed = true;
            } elseif ($oldTouched === null) {
                $changed = true;
            } else {
                $oldCarbon = \Carbon\Carbon::parse($oldTouched);
                $changed = $newTouched->gt($oldCarbon);
            }

            if ($changed) {
                $needFetch[$title] = $id;
            } else {
                $this->pageRepo->refreshFetchedAt($id);
                $classified['fresh'][$title] = $id;
            }
        }
        $classified['check'] = [];

        return $needFetch;
    }

    /**
     * Outgoing リンクを Wikipedia から取得して DB に保存する
     */
    private function fetchAndStoreOutgoing(array $titleIdMap): void
    {
        $titles = array_keys($titleIdMap);
        $total = count($titles);
        if ($total === 0)
            return;

        $this->emit('fetching_links', ['count' => $total]);
        $touchedMap = $this->api->getTouchedTimes($titles);
        $processed = 0;

        foreach (array_chunk($titles, 10) as $chunk) {
            $batchResult = $this->api->getBatchOutgoingLinks($chunk);

            foreach ($chunk as $title) {
                $processed++;
                $this->emit('fetching_progress', [
                    'current' => $processed,
                    'total' => $total,
                    'title' => $title,
                ]);

                $sourceId = $titleIdMap[$title];
                $linkTitles = array_values(array_unique($batchResult[$title] ?? []));

                if (empty($linkTitles)) {
                    \Log::warning('[LinkProvider] empty outgoing result from API, skipping save', [
                        'title' => $title,
                    ]);
                    $this->emit('empty_response', ['title' => $title]);
                    continue;
                }

                $targetIdMap = $this->pageRepo->ensurePages($linkTitles);
                $targetIds = array_values($targetIdMap);

                $this->linkRepo->replaceOutgoingLinks($sourceId, $targetIds);

                $this->pageRepo->upsertMeta(
                    $sourceId,
                    $touchedMap[$title] ?? null,
                    count($targetIds)
                );
            }
        }
    }

    /**
     * Incoming リンクを Wikipedia から取得して DB に保存する（バッチ版）。
     *
     * 50 タイトルずつまとめて API を叩き、リクエスト数を大幅に削減する。
     * Outgoing と違い、既存レコードを削除せず追加のみ行う(insertOrIgnore)。
     */
    private function fetchAndStoreIncoming(array $titleIdMap): void
    {
        $titles = array_keys($titleIdMap);
        $total = count($titles);
        if ($total === 0)
            return;

        $this->emit('fetching_incoming', ['count' => $total]);

        $processed = 0;

        // 50件ずつバッチで API 取得 → ページ単位で DB 保存
        foreach (array_chunk($titles, 50) as $chunk) {
            $batchResult = $this->api->getBatchIncomingLinks($chunk);

            foreach ($chunk as $title) {
                $processed++;
                $this->emit('fetching_progress', [
                    'current' => $processed,
                    'total' => $total,
                    'title' => $title,
                ]);

                $targetId = $titleIdMap[$title];
                $linkTitles = array_values(array_unique($batchResult[$title] ?? []));

                // 空応答: メタだけ記録（incoming_link_count=0 → 次回再取得）
                if (empty($linkTitles)) {
                    $this->pageRepo->upsertIncomingMeta($targetId, 0);
                    continue;
                }

                // リンク元ページのIDを確保
                $sourceIdMap = $this->pageRepo->ensurePages($linkTitles);
                $sourceIds = array_values($sourceIdMap);

                // 追加のみ(既存は壊さない)
                $this->linkRepo->addIncomingLinks($targetId, $sourceIds);

                // incoming メタを更新
                $this->pageRepo->upsertIncomingMeta($targetId, count($sourceIds));
            }
        }
    }
}