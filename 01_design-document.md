# Wikipedia 6クリック挑戦 — 設計書

## 1. プロジェクト概要

### 1.1 目的

「Wikipediaのどのページからどのページへも6クリック以内でたどり着ける」という仮説（Six Degrees of Wikipedia）を検証するWebアプリケーション。ユーザーが指定した2つのWikipediaページ間の最短リンク経路を、双方向BFS（幅優先探索）で発見し、リアルタイムに探索の進捗を表示する。

### 1.2 技術スタック

| レイヤー | 技術 |
|---------|------|
| バックエンド | PHP 8.3+ / Laravel 13.x |
| フロントエンド | Vanilla JavaScript（フレームワーク不使用）|
| CSS | 手書きCSS（Tailwind不使用、公開ページ向け）|
| データベース | SQLite（デフォルト）/ MySQL対応 |
| リアルタイム通信 | Server-Sent Events (SSE) |
| 外部API | Wikipedia MediaWiki API |
| ビルドツール | Vite（Laravelデフォルト、ただし探索ページでは未使用）|

### 1.3 機能一覧

1. **経路探索** — 2つのWikipediaページ間の最短経路をリアルタイム探索
2. **オートコンプリート** — Wikipedia OpenSearch APIを利用したページ名の補完
3. **探索履歴** — 過去の探索結果の一覧・詳細表示（フィルタ・ページネーション付き）
4. **統計ダッシュボード** — クリック数分布、ハブページ、人気ペアなどの集計
5. **リンクキャッシュ** — Wikipedia APIから取得したリンク情報をDBにキャッシュし、再探索を高速化

---

## 2. アーキテクチャ

### 2.1 全体構成図

```
[ブラウザ]
   │
   ├── GET /                    → PathFinderController@index   → finder.blade.php
   ├── GET /find-path/stream    → PathFinderController@stream  → SSE (text/event-stream)
   ├── GET /suggest             → PathFinderController@suggest → JSON
   ├── GET /history             → HistoryController@index      → history/index.blade.php
   ├── GET /history/{id}        → HistoryController@show       → history/show.blade.php
   └── GET /stats               → StatsController@index        → stats/index.blade.php
                                         │
                                         ▼
                              [Service Layer]
                         WikipediaPathFinder (BFS)
                                │        │
                         LinkProvider     WikipediaApiClient
                          (キャッシュ層)     (HTTP通信)
                                │                │
                                ▼                ▼
                         [Repository Layer]    Wikipedia API
                    PageRepository              (外部)
                    LinkRepository
                    SearchHistoryRepository
                                │
                                ▼
                         [Database]
                    pages, links, page_meta,
                    search_history, search_path_steps
```

### 2.2 レイヤー構成

本アプリは以下の4層で構成される。

| レイヤー | 役割 | 該当ディレクトリ |
|---------|------|---------------|
| Controller | HTTPリクエストの受付、レスポンス生成 | `app/Http/Controllers/` |
| Service | ビジネスロジック（BFS、API通信、キャッシュ判定）| `app/Services/` |
| Repository | データベースアクセスの抽象化 | `app/Repositories/` |
| Model | Eloquent ORM によるテーブル定義 | `app/Models/` |

---

## 3. データベース設計

### 3.1 ER図（テキスト表現）

```
pages (1) ──── (N) links (N) ──── (1) pages
  │                                     │
  │ (1)                                 │
  └──── page_meta                       │
  │                                     │
  │ (1)                                 │
  ├──── search_history.start_id ────────┘
  ├──── search_history.goal_id
  │         │
  │         │ (1)
  │         └──── (N) search_path_steps ──── pages
```

### 3.2 テーブル定義

#### `pages` — Wikipediaページのマスタ

| カラム | 型 | 説明 |
|-------|-----|------|
| id | UNSIGNED INT (PK, AUTO_INCREMENT) | ページID |
| title | VARCHAR(255) UNIQUE | Wikipediaのページタイトル（正規化済み）|

- `utf8mb4_bin` 照合順序を使用（タイトルの大文字・小文字を区別するため）

#### `links` — ページ間のリンク関係

| カラム | 型 | 説明 |
|-------|-----|------|
| source_id | UNSIGNED INT (PK, FK→pages) | リンク元ページ |
| target_id | UNSIGNED INT (PK, FK→pages) | リンク先ページ |

- 複合主キー `(source_id, target_id)`
- `target_id` にもインデックス（逆方向探索用）

#### `page_meta` — リンクキャッシュのメタ情報

| カラム | 型 | 説明 |
|-------|-----|------|
| page_id | UNSIGNED INT (PK, FK→pages) | 対象ページ |
| wiki_touched_at | DATETIME NULL | Wikipedia側の最終更新日時 |
| fetched_at | DATETIME | 最後にリンクを取得した日時 |
| link_count | UNSIGNED INT | 取得したリンク数 |

#### `search_history` — 探索実行の記録

| カラム | 型 | 説明 |
|-------|-----|------|
| id | BIGINT (PK, AUTO_INCREMENT) | 履歴ID |
| start_id | UNSIGNED INT (FK→pages) | スタートページ |
| goal_id | UNSIGNED INT (FK→pages) | ゴールページ |
| clicks | TINYINT NULL | 経路のクリック数（失敗時null）|
| found | BOOLEAN | 経路が見つかったか |
| duration_ms | UNSIGNED INT | 探索にかかった時間（ミリ秒）|
| api_calls | UNSIGNED INT | Wikipedia APIへのリクエスト回数 |
| visited_count | UNSIGNED INT | BFSで訪問したノード数 |
| max_depth_per_side | TINYINT | 使用した片側の最大深さ |
| searched_at | DATETIME | 探索日時 |

#### `search_path_steps` — 見つかった経路の各ステップ

| カラム | 型 | 説明 |
|-------|-----|------|
| history_id | BIGINT (PK, FK→search_history) | 対応する履歴 |
| step_index | TINYINT (PK) | 経路中の位置（0始まり）|
| page_id | UNSIGNED INT (FK→pages) | ステップのページ |

### 3.3 キャッシュ戦略（鮮度判定）

リンクデータの鮮度を3段階で判定する：

| 鮮度 | 条件 | 動作 |
|------|------|------|
| `fresh` | fetched_at から24時間以内 | DBのリンクをそのまま使用 |
| `check` | 24時間〜7日 | Wikipedia APIで`touched`を確認し、変更がなければfresh扱い |
| `stale` | 7日超 | Wikipedia APIからリンクを再取得 |
| `missing` | page_metaに行がない | 初回取得として扱う |

この戦略により、APIリクエスト数を抑えつつ、リンク情報の鮮度を維持する。

---

## 4. 探索アルゴリズム

### 4.1 双方向BFS

スタートからゴールへ向かう「前方探索」と、ゴールからスタートへ向かう「後方探索」を交互に行う。

```
スタート ──→ [前方フロンティア] ──→ ... ──→ 出会い点 ←── ... ←── [後方フロンティア] ←── ゴール
```

#### 方向の選択ロジック (`chooseSide`)

1. 両方の探索が終了（フロンティア空 or 深さ上限到達）→ 終了
2. 片方だけ終了 → もう片方を展開
3. 両方とも展開可能 → **フロンティアが小さい方**を選択（探索空間を抑制）

#### 前方展開 (`expandForward`)

- フロンティアの各ページについて、**出ていくリンク**を取得
- 新しいページが後方の訪問済みに含まれていれば → **出会い！**

#### 後方展開 (`expandBackward`)

- フロンティアの各ページについて、**入ってくるリンク（linkshere）**を取得
- 新しいページが前方の訪問済みに含まれていれば → **出会い！**

### 4.2 リンク取得の非対称性

| 方向 | API | キャッシュ | 理由 |
|------|-----|----------|------|
| 前方（outgoing） | `prop=links` | **あり**（DBに保存） | 1ページあたりのリンク数が有限で安定 |
| 後方（incoming） | `prop=linkshere` | **なし**（常にAPI） | 被リンク数は変動が大きく、巨大ページでは膨大 |

### 4.3 深さ制限

- 片側の最大深さ: 1〜5（デフォルト3、設定で変更可）
- 合計最大クリック数: `maxDepthPerSide × 2`（デフォルト6）

---

## 5. リアルタイム通信（SSE）

### 5.1 イベント一覧

| イベント名 | タイミング | ペイロード例 |
|-----------|-----------|-------------|
| `connected` | 接続確立時 | `{start, goal, depth}` |
| `normalize` | タイトル正規化時 | `{title, role}` |
| `search_start` | 探索開始 | `{start, goal, max_depth_total}` |
| `cache_classify` | キャッシュ判定完了 | `{fresh, check, stale, missing}` |
| `cache_check_touched` | touched確認開始 | `{count}` |
| `fetching_links` | リンク取得開始 | `{count}` |
| `fetching_progress` | リンク取得進捗 | `{current, total, title}` |
| `fetching_incoming` | 入リンク取得中 | `{count}` |
| `layer_start` | 層の展開開始 | `{direction, depth, frontier_size}` |
| `layer_end` | 層の展開完了 | `{direction, new_frontier_size}` |
| `meeting` | 出会い点発見 | `{node}` |
| `result` | 経路発見 | `{clicks, path, history_id}` |
| `error` | エラー発生 | `{message}` |
| `done` | 探索終了 | `{}` |

### 5.2 安全策

- **タイムアウト**: 540秒でハードタイムアウト
- **クライアント切断検知**: `connection_aborted()` で検知し、即座に探索を中止
- **シャットダウンハンドラ**: 異常終了時にも `error` と `done` イベントを送信
- **出力バッファリング無効化**: `ob_end_flush()`、`zlib.output_compression = 0` 等

---

## 6. ファイル構成と各ファイルの役割

### 6.1 Controllers（`app/Http/Controllers/`）

| ファイル | 役割 |
|---------|------|
| `PathFinderController.php` | メインの探索ページ。SSEストリーム、サジェストAPIを提供 |
| `HistoryController.php` | 探索履歴の一覧と詳細表示 |
| `StatsController.php` | 統計ダッシュボードのデータ集計と表示 |

### 6.2 Models（`app/Models/`）

| ファイル | テーブル | 特記事項 |
|---------|---------|---------|
| `Page.php` | pages | timestamps無効、outgoing/incomingリレーション |
| `Link.php` | links | 複合主キー、timestamps無効 |
| `PageMeta.php` | page_meta | page_idが主キー、日時キャスト |
| `SearchHistory.php` | search_history | timestamps無効（独自のsearched_at使用）|
| `SearchPathStep.php` | search_path_steps | 複合主キー、timestamps無効 |

### 6.3 Services（`app/Services/`）

| ファイル | 役割 |
|---------|------|
| `WikipediaPathFinder.php` | 双方向BFSアルゴリズムの実装。探索のオーケストレーション |
| `WikipediaApiClient.php` | Wikipedia MediaWiki APIとのHTTP通信を担当 |
| `LinkProvider.php` | リンク取得のキャッシュ層。DBキャッシュとAPIの仲介 |

### 6.4 Repositories（`app/Repositories/`）

| ファイル | 役割 |
|---------|------|
| `PageRepository.php` | ページの検索・作成、キャッシュ鮮度判定、メタ情報管理 |
| `LinkRepository.php` | リンクの読み取り・置換・削除 |
| `SearchHistoryRepository.php` | 探索結果の記録（append only）|

### 6.5 Views（`resources/views/`）

| ファイル | 用途 |
|---------|------|
| `layouts/app.blade.php` | 共通レイアウト（ナビバー、CSS読み込み）|
| `finder.blade.php` | 探索ページ（フォーム、SSE接続のJS呼び出し）|
| `history/index.blade.php` | 履歴一覧（フィルタ、ページネーション）|
| `history/show.blade.php` | 履歴詳細（経路の可視化）|
| `stats/index.blade.php` | 統計ダッシュボード |

### 6.6 フロントエンド（`public/`）

| ファイル | 役割 |
|---------|------|
| `css/app.css` | 全ページ共通のスタイル |
| `css/finder.css` | 探索ページ専用（プログレス、サジェスト、チップUI）|
| `css/stats.css` | 統計ページ専用（カード、棒グラフ）|
| `js/autocomplete.js` | Autocompleteクラス（サジェスト、チップ表示、キーボード操作）|
| `js/finder.js` | SSE接続、プログレス表示、経路レンダリング |

### 6.7 設定（`config/`）

| ファイル | 本アプリ固有の設定 |
|---------|-----------------|
| `finder.php` | 探索深さ、キャッシュTTL、APIプールサイズ、タイムアウト |

### 6.8 マイグレーション（`database/migrations/`）

| ファイル | 作成するテーブル |
|---------|---------------|
| `2026_05_03_014132_create_pages_table.php` | pages |
| `2026_05_03_014144_create_links_table.php` | links |
| `2026_05_03_014150_create_page_meta_table.php` | page_meta |
| `2026_05_03_014158_create_search_history_table.php` | search_history |
| `2026_05_03_014203_create_search_path_steps_table.php` | search_path_steps |

---

## 7. 外部API利用

### 7.1 Wikipedia MediaWiki API

| 機能 | エンドポイント | パラメータ |
|------|-------------|----------|
| タイトル正規化 | `action=query` | `titles`, `redirects=1` |
| 出ていくリンク取得 | `action=query`, `prop=links` | `pllimit=max`, `plnamespace=0` |
| 入ってくるリンク取得 | `action=query`, `prop=linkshere` | `lhlimit=max`, `lhnamespace=0`, `lhshow=!redirect` |
| 最終更新日時取得 | `action=query`, `prop=info` | titles（最大50件一括）|
| サジェスト | `action=opensearch` | `search`, `limit`, `namespace=0` |

### 7.2 APIリクエストの信頼性

- リトライ: 最大2回（5xx系エラーまたは通信エラー時）
- リトライ間隔: 500ms
- タイムアウト: 15秒（設定変更可）
- User-Agent: `WikiPathFinder/1.0 (Laravel demo)`
- 空応答ガード: APIが0件を返した場合、DBの既存データを上書きしない

---

## 8. フロントエンド設計

### 8.1 オートコンプリート

`Autocomplete` クラスが以下を担当：

1. **キーワード入力** → Wikipedia OpenSearch APIでサジェスト取得（220msデバウンス）
2. **候補選択** → タイトルとURLを「チップ」として表示
3. **URL直接入力** → ペーストやEnterでURLをパースし、自動的にチップ化
4. **キーボード操作** → ↑↓でカーソル移動、Enter で選択、Escape で閉じる

### 8.2 SSEプログレスUI

- **3つのカウンター**: 現在の層、探索ノード数、APIリクエスト数
- **ログパネル**: ターミナル風の黒背景にイベントを時刻付きで表示
- **経過時間タイマー**: `requestAnimationFrame` で0.1秒単位更新、5秒以上イベントがなければ警告色
- **方向別色分け**: 前方（緑）、後方（オレンジ）、出会い（ピンク）

---

## 9. セキュリティ・パフォーマンス考慮

### 9.1 入力バリデーション

- URLパース: 正規表現で `https://{lang}.wikipedia.org/wiki/{title}` 形式を検証
- 言語コード: `^[a-z-]{2,12}$` で制限
- 深さ: 設定ファイルの `min_depth_per_side`〜`max_depth_per_side` にクランプ

### 9.2 パフォーマンス

- リンクのチャンク挿入: 1,000件ずつ `insertOrIgnore`
- ページのバルク作成: `ensurePages` で一括upsert
- touched確認の一括化: Wikipedia APIの `titles` パラメータで最大50件ずつ一括取得
- `ignore_user_abort(false)`: ブラウザ閉鎖時に即座にPHPプロセスを停止

### 9.3 エラーハンドリング

- 失敗した探索も `found=false` で履歴に記録
- API部分エラー時は収集済みのリンクを返す（部分的成功）
- シャットダウンハンドラで異常終了時にもSSEの終了イベントを送信
