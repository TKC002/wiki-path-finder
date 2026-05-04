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
5. **リンクキャッシュ** — Wikipedia APIから取得したリンク情報（出ていくリンク・入ってくるリンクの両方向）をDBにキャッシュし、再探索を高速化

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
- 前方探索（outgoing）と後方探索（incoming）の両方のリンクを同じテーブルに格納

#### `page_meta` — リンクキャッシュのメタ情報

| カラム | 型 | 説明 |
|-------|-----|------|
| page_id | UNSIGNED INT (PK, FK→pages) | 対象ページ |
| wiki_touched_at | DATETIME NULL | Wikipedia側の最終更新日時 |
| fetched_at | DATETIME | 最後に**出ていくリンク**を取得した日時 |
| link_count | UNSIGNED INT | 取得した出ていくリンク数 |
| incoming_fetched_at | DATETIME NULL | 最後に**入ってくるリンク**を取得した日時 |
| incoming_link_count | UNSIGNED INT DEFAULT 0 | 取得した入ってくるリンク数 |

- `fetched_at` にインデックス（outgoing鮮度判定用）
- `incoming_fetched_at` にインデックス（incoming鮮度判定用）

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

リンクデータの鮮度を判定し、APIリクエスト数を抑えつつ情報の鮮度を維持する。出ていくリンク（outgoing）と入ってくるリンク（incoming）で判定ロジックが異なる。

#### Outgoing（前方）リンクの鮮度

| 鮮度 | 条件 | 動作 |
|------|------|------|
| `fresh` | `fetched_at` から24時間以内 | DBのリンクをそのまま使用 |
| `check` | 24時間超、または `link_count` が0 | Wikipedia APIで`touched`を確認し、変更がなければfresh扱い、変更があれば再取得 |
| `missing` | `page_meta` に行がない | 初回取得として扱う |

#### Incoming（後方）リンクの鮮度

Incomingリンクはターゲットページの `touched_at` では変化を検知できない（他のページの編集で変わるため）。そのため `check` は使わず、`fresh` / `missing` の2段階で判定する。

| 鮮度 | 条件 | 動作 |
|------|------|------|
| `fresh` | `incoming_fetched_at` から24時間以内 かつ `incoming_link_count > 0` | DBのリンクをそのまま使用 |
| `missing` | それ以外（行なし、null、0件、24時間超） | APIから再取得 |

---

## 4. 探索アルゴリズム

### 4.1 双方向BFS

スタートからゴールへ向かう「前方探索」と、ゴールからスタートへ向かう「後方探索」を交互に行う。

```
スタート ──→ [前方フロンティア] ──→ ... ──→ 出会い点 ←── ... ←── [後方フロンティア] ←── ゴール
```

#### 方向の選択ロジック (`chooseSide`)

1. **いずれかのフロンティアが空** → 経路なし（グラフが断絶）として即終了
2. 両方の深さが上限到達 → 終了
3. 片方だけ深さ上限 → もう片方を展開
4. 両方とも展開可能 → **フロンティアが小さい方**を選択（探索空間を抑制）

#### 前方展開 (`expandForward`)

- フロンティアの各ページについて、**出ていくリンク**を取得
- 新しいページが後方の訪問済みに含まれていれば → **出会い！**

#### 後方展開 (`expandBackward`)

- フロンティアの各ページについて、**入ってくるリンク（linkshere）**を取得
- 新しいページが前方の訪問済みに含まれていれば → **出会い！**

#### 空フロンティアのリトライ

展開後にフロンティアが空になった場合、API失敗の可能性があるため最大2回リトライする。リトライでもフロンティアが空なら、グラフ断絶として探索を終了する。

### 4.2 リンク取得とキャッシュ

| 方向 | API | キャッシュ | キャッシュ方式 |
|------|-----|----------|-------------|
| 前方（outgoing） | `prop=links` | **あり** | 全リンクをDB保存、touched比較で鮮度判定 |
| 後方（incoming） | `prop=linkshere` | **あり** | DB保存（追加のみ、既存は削除しない）、24h TTLで鮮度判定 |

- Outgoing: `replaceOutgoingLinks()`でリンクを全置換（正確性重視）
- Incoming: `addIncomingLinks()`で`insertOrIgnore`による追加のみ（前方探索が保存したリンクを壊さない）
- バッチAPI取得: outgoing は10件ずつ、incoming は10件ずつAPIバッチ → 50件ずつDBバッチで処理

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
| `search_start` | 探索開始 | `{start, goal, max_depth_per_side, max_depth_total}` |
| `cache_classify` | キャッシュ判定完了 | `{fresh, check, missing}` |
| `cache_check_touched` | touched確認開始 | `{count}` |
| `fetching_links` | 出リンク取得開始 | `{count}` |
| `fetching_incoming` | 入リンク取得開始 | `{count}` |
| `fetching_progress` | リンク取得進捗 | `{current, total, title}` |
| `empty_response` | APIが空応答を返した | `{title}` |
| `layer_start` | 層の展開開始 | `{direction, depth, frontier_size, total_depth}` |
| `layer_end` | 層の展開完了 | `{direction, depth, new_frontier_size}` |
| `retry` | 空フロンティアのリトライ | `{direction, attempt, frontier_size}` |
| `meeting` | 出会い点発見 | `{node}` |
| `result` | 経路発見 | `{clicks, path, history_id, duration_ms}` |
| `error` | エラー発生 | `{message}` |
| `done` | 探索終了 | `{}` |

各進捗イベントには `api_calls`（累計APIリクエスト数）と `visited_count`（累計訪問ノード数）が自動付加される。

### 5.2 安全策

- **タイムアウト**: 540秒でアプリレベルのハードタイムアウト（`set_time_limit(0)` でPHP側は無制限）
- **クライアント切断検知**: `connection_aborted()` で検知し、例外を投げて探索を中止
- **シャットダウンハンドラ**: 異常終了時にも `error` と `done` イベントを送信
- **出力バッファリング無効化**: `ob_end_flush()`、`zlib.output_compression = 0` 等
- **Nginxバッファリング無効化**: `X-Accel-Buffering: no` ヘッダ

---

## 6. ファイル構成と各ファイルの役割

### 6.1 Controllers（`app/Http/Controllers/`）

| ファイル | 役割 |
|---------|------|
| `PathFinderController.php` | メインの探索ページ。SSEストリーム、サジェストAPIを提供。履歴記録ヘルパーメソッドを内包 |
| `HistoryController.php` | 探索履歴の一覧と詳細表示 |
| `StatsController.php` | 統計ダッシュボードのデータ集計と表示 |

### 6.2 Models（`app/Models/`）

| ファイル | テーブル | 特記事項 |
|---------|---------|---------|
| `Page.php` | pages | timestamps無効、outgoing/incomingリレーション |
| `Link.php` | links | 複合主キー、timestamps無効 |
| `PageMeta.php` | page_meta | page_idが主キー、日時キャスト（incoming含む）|
| `SearchHistory.php` | search_history | timestamps無効（独自のsearched_at使用）|
| `SearchPathStep.php` | search_path_steps | 複合主キー、timestamps無効 |

### 6.3 Services（`app/Services/`）

| ファイル | 役割 |
|---------|------|
| `WikipediaPathFinder.php` | 双方向BFSアルゴリズムの実装。探索のオーケストレーション |
| `WikipediaApiClient.php` | Wikipedia MediaWiki APIとのHTTP通信。アダプティブスロットリング、リトライ、バッチ取得を担当 |
| `LinkProvider.php` | リンク取得のキャッシュ層。outgoing/incoming両方向のDBキャッシュとAPIの仲介 |

### 6.4 Repositories（`app/Repositories/`）

| ファイル | 役割 |
|---------|------|
| `PageRepository.php` | ページの検索・作成、outgoing/incoming両方向のキャッシュ鮮度判定、メタ情報管理 |
| `LinkRepository.php` | リンクの読み取り（outgoing/incoming）・置換・追加・削除 |
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

| ファイル | 作成するテーブル / 変更 |
|---------|----------------------|
| `2026_05_03_014132_create_pages_table.php` | pages |
| `2026_05_03_014144_create_links_table.php` | links |
| `2026_05_03_014150_create_page_meta_table.php` | page_meta |
| `2026_05_03_014158_create_search_history_table.php` | search_history |
| `2026_05_03_014203_create_search_path_steps_table.php` | search_path_steps |
| `2026_05_03_023275_add_incoming_cache_to_page_meta.php` | page_metaにincoming用カラム追加 |

---

## 7. 外部API利用

### 7.1 Wikipedia MediaWiki API

| 機能 | エンドポイント | パラメータ |
|------|-------------|----------|
| タイトル正規化 | `action=query` | `titles`, `redirects=1` |
| 出ていくリンク取得 | `action=query`, `prop=links` | `pllimit=max`, `plnamespace=0` |
| 出ていくリンク一括取得 | `action=query`, `prop=links` | `titles`（パイプ区切り10件ずつ）|
| 入ってくるリンク取得 | `action=query`, `prop=linkshere` | `lhlimit=max`, `lhnamespace=0`, `lhshow=!redirect` |
| 入ってくるリンク一括取得 | `action=query`, `prop=linkshere` | `titles`（パイプ区切り10件ずつ）|
| 最終更新日時取得 | `action=query`, `prop=info` | titles（最大50件一括）|
| サジェスト | `action=opensearch` | `search`, `limit`, `namespace=0` |

### 7.2 APIリクエストの信頼性

- **リトライ**: 最大4回（429レートリミットまたは5xxエラー時）
- **アダプティブスロットリング**: リクエスト間に最低1秒の間隔（初期値）
  - 429受信時: スロットル間隔を2倍に引き上げ（最大5秒）
  - 成功時: スロットル間隔を10%ずつ緩和（基底値まで戻す）
- **Retry-Afterヘッダ対応**: 429レスポンスにRetry-Afterが含まれる場合はその秒数を優先
- **指数バックオフ**: 429は5s→10s→20s、5xxは500ms→1s→2s
- **タイムアウト**: 15秒（設定変更可）
- **User-Agent**: `WikiPathFinder/1.0 (takeshilingmu027@gmail.com)`
- **空応答ガード**: APIが0件を返した場合、DBの既存データを上書きしない

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
- 多重エンコード対策: `%25XX` パターンを最大5回デコード
- 言語コード: `^[a-z-]{2,12}$` で制限
- 深さ: 設定ファイルの `min_depth_per_side`〜`max_depth_per_side` にクランプ

### 9.2 パフォーマンス

- リンクのチャンク挿入: 1,000件ずつ `insertOrIgnore`
- ページのバルク作成: `ensurePages` で一括upsert（10,000件ずつチャンク処理）
- touched確認の一括化: Wikipedia APIの `titles` パラメータで最大50件ずつ一括取得
- バッチAPI取得: outgoing/incoming共に複数タイトルをパイプ区切りでまとめて取得
- `ignore_user_abort(false)`: ブラウザ閉鎖時に即座にPHPプロセスを停止

### 9.3 エラーハンドリング

- 失敗した探索も `found=false` で履歴に記録（履歴記録の失敗は探索結果に影響しないよう `try-catch` で防御）
- API部分エラー時は収集済みのリンクを返す（部分的成功）
- シャットダウンハンドラで異常終了時にもSSEの終了イベントを送信
- 空フロンティア時のリトライで一時的なAPI障害を吸収
