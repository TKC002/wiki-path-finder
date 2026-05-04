# Laravel 指南書 — Wikipedia経路探索アプリで学ぶ実践Laravel

> **対象読者**: PHPの基本文法は理解しているが、Laravelは初めてという方  
> **教材アプリ**: Wikipedia 6クリック挑戦（最短リンク経路探索）  
> **Laravel バージョン**: 13.x（Laravel 11以降で共通する概念が多い）

---

## 目次

1. [Laravelとは何か](#1-laravelとは何か)
2. [Laravelのディレクトリ構成 — どこに何を置くのか](#2-laravelのディレクトリ構成--どこに何を置くのか)
3. [MVC — Laravelの基本設計パターン](#3-mvc--laravelの基本設計パターン)
4. [ルーティング — URLとコントローラの接続](#4-ルーティング--urlとコントローラの接続)
5. [Controller — リクエストを受け取る門番](#5-controller--リクエストを受け取る門番)
6. [Model — データベースとのやり取り](#6-model--データベースとのやり取り)
7. [View（Blade） — 画面を作る](#7-viewblade--画面を作る)
8. [マイグレーション — テーブル定義をコードで管理する](#8-マイグレーション--テーブル定義をコードで管理する)
9. [設定ファイル（Config） — 環境に応じた値管理](#9-設定ファイルconfig--環境に応じた値管理)
10. [Service — Laravelの「標準にない」フォルダ①](#10-service--laravelの標準にないフォルダ)
11. [Repository — Laravelの「標準にない」フォルダ②](#11-repository--laravelの標準にないフォルダ)
12. [なぜ標準にないフォルダを作るのか — レイヤードアーキテクチャ](#12-なぜ標準にないフォルダを作るのか--レイヤードアーキテクチャ)
13. [Eloquent ORM の実践テクニック](#13-eloquent-orm-の実践テクニック)
14. [SSE（Server-Sent Events） — リアルタイム通信](#14-sseserver-sent-events--リアルタイム通信)
15. [HTTPクライアント（Http ファサード）](#15-httpクライアントhttp-ファサード)
16. [ロギング](#16-ロギング)
17. [サービスコンテナと依存性注入](#17-サービスコンテナと依存性注入)
18. [ファサードとは](#18-ファサードとは)
19. [ミドルウェア](#19-ミドルウェア)
20. [Artisanコマンド](#20-artisanコマンド)
21. [フロントエンド資産の管理](#21-フロントエンド資産の管理)
22. [テスト](#22-テスト)
23. [本アプリで使われているLaravelの機能一覧](#23-本アプリで使われているlaravelの機能一覧)
24. [本アプリで採用しなかったLaravel機能](#24-本アプリで採用しなかったlaravel機能)
25. [Laravelをさらに学ぶための道筋](#25-laravelをさらに学ぶための道筋)

---

## 1. Laravelとは何か

LaravelはPHPで書かれた**Webアプリケーションフレームワーク**です。PHPの世界ではデファクトスタンダードと言ってよい存在です。

### 1.1 「フレームワーク」とは

「ライブラリ」が「自分のコードから呼び出すもの」だとすれば、「フレームワーク」は **「自分のコードを呼び出してくれる土台」**です。たとえばルーティングを定義すると、ブラウザからのリクエストに対してLaravelが該当する**コントローラのメソッドを自動的に呼び出してくれます**。HTTPリクエストのパースや、レスポンスヘッダの生成といった定型作業を全てフレームワークが引き受けてくれる。これがフレームワークの恩恵です。

### 1.2 Laravelが提供するもの

| カテゴリ | 例 |
|---|---|
| ルーティング | URLとコードの紐付け |
| MVC支援 | Controller, Model, View の枠組み |
| ORM | Eloquent（DBをPHPオブジェクトとして扱う） |
| テンプレートエンジン | Blade |
| バリデーション | リクエストパラメータの検証 |
| 認証・認可 | ログイン機能の枠組み |
| マイグレーション | DBスキーマをコードで管理 |
| キュー | 非同期ジョブ |
| イベントシステム | リスナーパターンの仕組み |
| キャッシュ | Cache ファサード |
| ログ | Monolog ベースの統一ログ |
| HTTPクライアント | Guzzle ベースの統一API |
| テスト | PHPUnit 統合 |
| Artisan CLI | 開発支援コマンド群 |

本アプリで使っているのはこの一部です。必要になったら学ぶ方針で十分。

### 1.3 設計思想：Convention over Configuration

Laravelには「設定より規約」の思想があります。たとえば：

- `App\Http\Controllers\PathFinderController` というクラスは `app/Http/Controllers/PathFinderController.php` に置く
- `view('finder')` と書けば `resources/views/finder.blade.php` が自動的に読まれる
- `App\Models\User` というモデルはデフォルトで `users` テーブル（複数形）に対応する

このような「お約束」を守れば設定ファイルを書かなくてよい、という設計です。最初は不思議に感じるかもしれませんが、慣れると非常に効率的です。

---

## 2. Laravelのディレクトリ構成 — どこに何を置くのか

Laravelを `composer create-project` で作成すると、以下のようなディレクトリ構成になる。本アプリでは、標準構成に加えて **独自のフォルダ**（`Services/`, `Repositories/`）を追加している。

```
プロジェクトルート/
├── app/                         ← アプリケーションコードの本体
│   ├── Http/
│   │   └── Controllers/         ← ★ コントローラ（MVCの「C」）
│   │       ├── Controller.php          ← 基底クラス（Laravel標準）
│   │       ├── PathFinderController.php ← 探索ページ
│   │       ├── HistoryController.php    ← 履歴ページ
│   │       └── StatsController.php      ← 統計ページ
│   ├── Models/                  ← ★ モデル（MVCの「M」）
│   │   ├── Page.php
│   │   ├── Link.php
│   │   ├── PageMeta.php
│   │   ├── SearchHistory.php
│   │   └── SearchPathStep.php
│   ├── Services/                ← ◆ 独自追加：ビジネスロジック
│   │   ├── WikipediaPathFinder.php
│   │   ├── WikipediaApiClient.php
│   │   └── LinkProvider.php
│   ├── Repositories/            ← ◆ 独自追加：DB操作の抽象化
│   │   ├── PageRepository.php
│   │   ├── LinkRepository.php
│   │   └── SearchHistoryRepository.php
│   └── Providers/               ← サービスプロバイダ（DIコンテナ設定）
│       └── AppServiceProvider.php
│
├── bootstrap/                   ← アプリ起動設定
│   └── app.php                  ← ルーティング・ミドルウェア・例外ハンドラの登録
│
├── config/                      ← ★ 設定ファイル群
│   ├── app.php                  ← アプリ名、タイムゾーン、暗号キーなど
│   ├── database.php             ← DB接続設定
│   ├── finder.php               ← ◆ 独自追加：探索エンジンの設定
│   └── ...
│
├── database/
│   ├── factories/               ← テスト用のダミーデータ生成
│   ├── migrations/              ← ★ マイグレーション（テーブル定義）
│   └── seeders/                 ← 初期データ投入
│
├── public/                      ← Webサーバーの公開ルート
│   ├── index.php                ← エントリーポイント（全リクエストがここを通る）
│   ├── css/                     ← ◆ 静的CSS（Viteを通さない）
│   └── js/                      ← ◆ 静的JS（Viteを通さない）
│
├── resources/
│   ├── views/                   ← ★ Bladeテンプレート（MVCの「V」）
│   │   ├── layouts/app.blade.php
│   │   ├── finder.blade.php
│   │   ├── history/
│   │   └── stats/
│   ├── css/app.css              ← Vite経由のCSS（Tailwind）
│   └── js/app.js                ← Vite経由のJS
│
├── routes/
│   ├── web.php                  ← ★ Webルート定義
│   └── console.php              ← Artisanコマンド定義
│
├── storage/                     ← ログ、キャッシュ、セッション等
├── tests/                       ← テストコード
├── vendor/                      ← Composerパッケージ（Git管理外）
├── composer.json                ← PHP依存関係
├── package.json                 ← Node.js依存関係
├── vite.config.js               ← Vite設定
├── .env                         ← 環境変数（Git管理外）
└── .env.example                 ← .envのテンプレート
```

**★ = Laravel標準のフォルダ**、**◆ = 開発者が独自に追加したフォルダ**

### 重要な原則

> **Laravelでは「どの種類のコードをどこに置くか」が明確に決まっている。**
> この規約に従うことで、他の開発者がプロジェクトに参加したとき「あのコードはどこにあるか」を迷わない。

---

## 3. MVC — Laravelの基本設計パターン

MVCとは **Model-View-Controller** の略で、アプリケーションを3つの役割に分離する設計パターンだ。

```
[ユーザーのリクエスト]
        │
        ▼
   Controller ←── ルーティング（routes/web.php）で振り分け
        │
    ┌───┴───┐
    ▼       ▼
  Model    View
 (データ)  (画面)
```

### 本アプリでのMVC

| 層 | 具体例 | 役割 |
|----|--------|------|
| **Model** | `Page.php`, `SearchHistory.php` | データベースのテーブルを表現。リレーション（関連）を定義 |
| **View** | `finder.blade.php`, `history/index.blade.php` | HTMLを生成。Bladeテンプレートエンジンで動的な値を埋め込む |
| **Controller** | `PathFinderController.php`, `HistoryController.php` | リクエストを受け取り、Modelからデータを取得し、Viewに渡す |

### MVCの限界と拡張

純粋なMVCだけでは、複雑なビジネスロジックの置き場所に困る。「BFSアルゴリズム」はControllerに書くには長すぎるし、Modelに書くのもテーブルと無関係だ。そこで **Service層** が登場する（後述）。

---

## 4. ルーティング — URLとコントローラの接続

### `routes/web.php` — 全URLの定義

```php
// GET / にアクセスしたら PathFinderController の index メソッドを実行
Route::get('/', [PathFinderController::class, 'index'])->name('finder.index');

// SSEストリーム
Route::get('/find-path/stream', [PathFinderController::class, 'stream'])->name('finder.stream');

// サジェストAPI
Route::get('/suggest', [PathFinderController::class, 'suggest'])->name('finder.suggest');

// 履歴
Route::get('/history',      [HistoryController::class, 'index'])->name('history.index');
Route::get('/history/{id}', [HistoryController::class, 'show'])->name('history.show')->whereNumber('id');

// 統計
Route::get('/stats',        [StatsController::class, 'index'])->name('stats.index');
```

### ポイント解説

- **`Route::get(URL, [Controller, メソッド])`**: GETリクエストに対するルートを定義
- **`->name('finder.index')`**: ルートに名前を付ける。Bladeテンプレートで `route('finder.index')` のようにURLを生成できる
- **`{id}`**: URLパラメータ。`/history/42` なら `$id = 42` がコントローラに渡される
- **`->whereNumber('id')`**: `{id}` が数値であることを制約。`/history/abc` は404になる

### Bladeテンプレートからルートを参照する

```blade
{{-- ルート名を使ってURLを生成（URLのハードコードを避ける） --}}
<a href="{{ route('finder.index') }}">探索</a>
<a href="{{ route('history.show', $h->id) }}">詳細</a>
```

---

## 5. Controller — リクエストを受け取る門番

### 基本原則

> **コントローラは「薄く」保つ。ビジネスロジックは書かない。**
> コントローラの仕事は「リクエストを受け取り」「適切なサービスに処理を依頼し」「レスポンスを返す」だけ。

### 例: HistoryController（シンプルなCRUD）

```php
class HistoryController
{
    public function index(Request $request): View
    {
        $query = SearchHistory::with(['startPage', 'goalPage'])
            ->orderByDesc('searched_at');

        // フィルタ処理
        $filter = $request->query('filter', 'all');
        if ($filter === 'found') {
            $query->where('found', true);
        } elseif ($filter === 'failed') {
            $query->where('found', false);
        }

        // ページネーション（20件ずつ）
        $histories = $query->paginate(20)->appends($request->query());

        // Viewにデータを渡して返す
        return view('history.index', [
            'histories' => $histories,
            'filter'    => $filter,
        ]);
    }
}
```

**ここで学べること:**

1. **`Request $request`** — Laravelが自動的にHTTPリクエスト情報を注入してくれる（依存性注入）
2. **`$request->query('filter', 'all')`** — クエリパラメータの取得（デフォルト値付き）
3. **`::with(['startPage', 'goalPage'])`** — Eager Loading（N+1問題の防止、後述）
4. **`->paginate(20)`** — 自動ページネーション
5. **`view('history.index', [...])`** — Bladeテンプレートにデータを渡す

### 例: PathFinderController@stream（高度な使い方）

SSEストリームを返すコントローラメソッドは、Laravelの `response()->stream()` を使う：

```php
public function stream(Request $request): StreamedResponse
{
    return response()->stream(function () use ($startUrl, $goalUrl, $depth) {
        // この中でSSEイベントを送信し続ける
        $finder = new WikipediaPathFinder($start['lang'], $depth);
        $result = $finder->findPath($start['title'], $goal['title']);
        // ...
    }, 200, [
        'Content-Type'      => 'text/event-stream; charset=UTF-8',
        'Cache-Control'     => 'no-cache, no-transform',
        'X-Accel-Buffering' => 'no',         // Nginx のバッファリングを無効化
        'Connection'        => 'keep-alive',
    ]);
}
```

**通常のコントローラとの違い:**

- `View` ではなく `StreamedResponse` を返す
- レスポンスヘッダを明示的に指定（`X-Accel-Buffering: no` でNginxのバッファリングも無効化）
- コールバック内でリアルタイムにデータを出力

---

## 6. Model — データベースとのやり取り

### Eloquent ORM とは

LaravelのORM（Object-Relational Mapping）で、**データベースのテーブルをPHPのクラスとして扱える**。SQLを書かずに、PHPのメソッドチェーンでクエリを組み立てる。

### 基本的なModelの書き方

```php
class Page extends Model
{
    protected $table = 'pages';       // 対応するテーブル名
    public $timestamps = false;       // created_at / updated_at を使わない

    protected $fillable = ['title'];  // 一括代入を許可するカラム

    // リレーション定義: このページから出ていくリンク
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'source_id');
    }

    // リレーション定義: このページに入ってくるリンク
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'target_id');
    }

    // リレーション定義: メタ情報（1対1）
    public function meta(): HasOne
    {
        return $this->hasOne(PageMeta::class, 'page_id');
    }
}
```

### 重要な設定項目

| プロパティ | 意味 | 本アプリでの使い方 |
|-----------|------|-----------------|
| `$table` | テーブル名を明示 | Laravelのデフォルトはクラス名の複数形（`Page`→`pages`）だが、明示する方が安全 |
| `$timestamps` | `created_at`/`updated_at` の自動管理 | 本アプリでは全モデルで `false`。独自の日時カラムを使用 |
| `$fillable` | 一括代入で設定可能なカラム | マスアサインメント脆弱性の防止 |
| `$casts` | カラムの型変換 | `'found' => 'boolean'`, `'searched_at' => 'datetime'` など |
| `$primaryKey` | 主キーのカラム名変更 | `PageMeta` は `page_id` を主キーにしている |
| `$incrementing` | AUTO_INCREMENTの有無 | 複合主キーのモデル（`Link`, `SearchPathStep`）では `false` |

### 複合主キーのモデル

LaravelのEloquentは複合主キーを「完全には」サポートしていない。本アプリでは以下のように対応している：

```php
class Link extends Model
{
    public $incrementing = false;     // AUTO_INCREMENTではない
    protected $primaryKey = null;     // 複合主キーなのでnull

    // ※ find($id) や save() は使えないが、
    //    クエリビルダ経由の操作（where, insert, delete）は問題なく動く
}
```

### リレーション（関連）

| 種類 | 例 | 意味 |
|------|-----|------|
| `HasMany` | `Page → Link (outgoingLinks)` | 1つのページに複数のリンクがある |
| `HasOne` | `Page → PageMeta (meta)` | 1つのページに1つのメタ情報 |
| `BelongsTo` | `SearchHistory → Page (startPage)` | 探索履歴は1つのスタートページに属する |

```php
// SearchHistory のリレーション定義
public function startPage(): BelongsTo
{
    return $this->belongsTo(Page::class, 'start_id');
}

// 使い方（コントローラやリポジトリで）
$history = SearchHistory::with(['startPage', 'goalPage'])->find($id);
echo $history->startPage->title;  // → "日本"
```

---

## 7. View（Blade） — 画面を作る

### Bladeテンプレートとは

LaravelのテンプレートエンジンであるBladeは、HTMLの中にPHPのロジックを簡潔に埋め込める。ファイル名は必ず `.blade.php` とする。

### レイアウトの継承

#### 親レイアウト (`layouts/app.blade.php`)

```blade
<!DOCTYPE html>
<html lang="ja">
<head>
    <title>@yield('title', 'デフォルトタイトル')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('styles')     {{-- 子テンプレートから追加されるCSS --}}
</head>
<body>
    <nav class="navbar">...</nav>
    <div class="container">
        @yield('content')  {{-- 子テンプレートのメインコンテンツ --}}
    </div>
    @stack('scripts')      {{-- 子テンプレートから追加されるJS --}}
</body>
</html>
```

#### 子テンプレート (`finder.blade.php`)

```blade
@extends('layouts.app')                     {{-- 親レイアウトを継承 --}}

@section('title', 'Wikipedia 6クリック挑戦') {{-- タイトルを上書き --}}

@push('styles')                              {{-- CSSを追加 --}}
    <link rel="stylesheet" href="{{ asset('css/finder.css') }}">
@endpush

@section('content')                          {{-- メインコンテンツ --}}
<div class="card">
    <h1>🔗 Wikipedia 6クリック挑戦</h1>
    ...
</div>
@endsection

@push('scripts')                             {{-- JSを追加 --}}
<script>
    window.FINDER_CONFIG = {
        suggestUrl: '{{ route("finder.suggest") }}',
        streamUrl: '{{ route("finder.stream") }}',
    };
</script>
<script src="{{ asset('js/finder.js') }}"></script>
@endpush
```

### よく使うBlade構文

```blade
{{-- 変数の出力（自動エスケープ） --}}
{{ $history->startPage->title }}

{{-- エスケープなし（HTML出力、ページネーションリンクなどに使う） --}}
{!! $histories->links() !!}

{{-- 条件分岐 --}}
@if ($history->found)
    <span class="badge badge-found">成功</span>
@else
    <span class="badge badge-failed">失敗</span>
@endif

{{-- ループ --}}
@foreach ($pathSteps as $i => $step)
    <div>{{ $step['title'] }}</div>
@endforeach

{{-- 空の場合の表示 --}}
@if ($histories->isEmpty())
    <div class="empty">まだ履歴がありません。</div>
@endif

{{-- ヘルパー関数 --}}
{{ asset('css/app.css') }}        {{-- publicディレクトリのファイルURL --}}
{{ route('history.show', $id) }}  {{-- 名前付きルートからURL生成 --}}
{{ number_format($value) }}       {{-- 数値フォーマット --}}
```

### Bladeからコントローラへデータを渡す流れ

```
Controller: return view('history.index', ['histories' => $histories]);
    │
    ▼
View: @foreach ($histories as $h) ... $h->startPage->title ... @endforeach
```

---

## 8. マイグレーション — テーブル定義をコードで管理する

### なぜマイグレーションが必要か

SQLファイルを手動で実行する代わりに、**テーブルの作成・変更をPHPコードで記述**する。これにより以下のメリットがある。

- チーム全員が同じDB構造を再現できる
- テーブル変更の履歴がGitで追跡できる
- `php artisan migrate` 一発で全テーブルを構築できる

### マイグレーションの例（pagesテーブル）

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->unsignedInteger('id', true);            // AUTO_INCREMENT
            $table->string('title', 255);
            $table->unique('title', 'uniq_title');
        });

        // 照合順序の変更（Laravelの標準メソッドでは設定できないため、生SQL）
        \DB::statement('ALTER TABLE pages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
```

### 主なカラム定義メソッド

| メソッド | SQL型 | 用途 |
|---------|------|------|
| `$table->id()` | BIGINT AUTO_INCREMENT | 通常の主キー |
| `$table->unsignedInteger('id', true)` | UNSIGNED INT AUTO_INCREMENT | 正の整数の主キー |
| `$table->string('title', 255)` | VARCHAR(255) | 文字列 |
| `$table->boolean('found')` | TINYINT(1) | 真偽値 |
| `$table->dateTime('searched_at')` | DATETIME | 日時 |
| `$table->unsignedTinyInteger('clicks')` | UNSIGNED TINYINT | 小さい正の整数 |

### インデックスと外部キー

```php
// 複合主キー
$table->primary(['source_id', 'target_id']);

// 通常のインデックス
$table->index('target_id', 'idx_target');

// 外部キー制約
$table->foreign('source_id')
      ->references('id')->on('pages')
      ->cascadeOnDelete();  // 親が削除されたら子も削除
```

### コマンド

```bash
# マイグレーション実行（未実行のものだけ）
php artisan migrate

# 全テーブルを削除して再作成
php artisan migrate:fresh

# マイグレーション状態の確認
php artisan migrate:status
```

---

## 9. 設定ファイル（Config） — 環境に応じた値管理

### 標準の設定ファイル

`config/` ディレクトリには、Laravel標準で `app.php`, `database.php`, `cache.php`, `session.php` 等がある。

### 独自の設定ファイル (`config/finder.php`)

本アプリでは、探索エンジン固有の設定をまとめた `finder.php` を追加している：

```php
return [
    'default_max_depth_per_side' => env('FINDER_DEFAULT_DEPTH', 3),
    'min_depth_per_side' => 1,
    'max_depth_per_side' => 5,
    'fresh_ttl_hours'    => env('FINDER_FRESH_TTL_HOURS', 24),
    'max_ttl_days'       => env('FINDER_MAX_TTL_DAYS', 7),
    'pool_size'          => 20,
    'timeout'            => 15,
];
```

### 設定値の読み方

```php
// config() ヘルパー関数で読む
$depth = config('finder.default_max_depth_per_side', 3);
// 第2引数はデフォルト値（設定ファイルが見つからない場合のフォールバック）
```

### `env()` 関数と `.env` ファイル

```
# .env ファイル（Git管理外）
FINDER_DEFAULT_DEPTH=3
FINDER_FRESH_TTL_HOURS=24
```

- **`env('KEY', default)`** — 環境変数を読む
- 本番と開発で異なる値（APIキー、DB接続先など）は `.env` に書く
- `.env` は `.gitignore` に入っているため、リポジトリには含まれない
- `.env.example` をテンプレートとしてリポジトリに含める

---

## 10. Service — Laravelの「標準にない」フォルダ①

### なぜServiceフォルダが必要なのか

Laravelの `php artisan make:model` や `php artisan make:controller` では、`Services/` フォルダは作られない。これは **開発者が必要に応じて自分で追加するフォルダ** だ。

本アプリには、Controllerに書くには複雑すぎるロジックがある：

- 双方向BFSアルゴリズム
- Wikipedia APIとの通信
- リンクキャッシュの鮮度判定

これらをControllerに全部書くと、1つのメソッドが数百行になり、テストもできず、再利用もできない。

### `app/Services/` の3つのクラス

#### `WikipediaPathFinder.php` — 探索エンジン

**責務**: 双方向BFSアルゴリズムだけに集中。

```php
class WikipediaPathFinder
{
    // BFSのメインメソッド
    public function findPath(string $startTitle, string $goalTitle): array
    {
        // 1. タイトル正規化
        // 2. 同一ページチェック
        // 3. 双方向BFSループ
        //    - chooseSide() で展開する方向を選択
        //    - expandForward() or expandBackward() で1層展開
        //    - 出会い点があれば buildResult() で経路構築
        // 4. 結果を返す
    }
}
```

**設計のポイント**: リンクの取得方法（API? DB?）は `LinkProvider` に委譲しており、BFSアルゴリズム自体はデータの取得元を知らない。

#### `WikipediaApiClient.php` — API通信

**責務**: Wikipedia MediaWiki APIへのHTTPリクエストだけに集中。

```php
class WikipediaApiClient
{
    // タイトル正規化（リダイレクト追従）
    public function normalizeTitle(string $title): ?string { ... }

    // 出ていくリンクの全取得（ページネーション対応）
    public function getAllOutgoingLinks(string $title): array { ... }

    // 出ていくリンクの一括取得（バッチ版、10件ずつ）
    public function getBatchOutgoingLinks(array $titles): array { ... }

    // 入ってくるリンクの全取得
    public function getAllIncomingLinks(string $title): array { ... }

    // 入ってくるリンクの一括取得（バッチ版、10件ずつ）
    public function getBatchIncomingLinks(array $titles): array { ... }

    // 最終更新日時の一括取得（キャッシュ鮮度判定用）
    public function getTouchedTimes(array $titles): array { ... }

    // オートコンプリート候補
    public function suggest(string $query, int $limit = 10): array { ... }
}
```

**設計のポイント**: アダプティブスロットリング（1〜5秒の間隔調整）とリトライロジック（最大4回、429レートリミット対応含む）が内部に閉じ込められている。呼び出し側はリトライやレート制限を意識しない。

#### `LinkProvider.php` — キャッシュ層

**責務**: 「DBにキャッシュがあるか？」「鮮度は？」「APIから取り直すべきか？」を判断し、適切なリンクデータを返す。outgoing/incoming両方向のキャッシュを管理する。

```
[PathFinder] → getOutgoingLinks(["日本", "東京"])
               getIncomingLinks(["ヘミングウェイ"])
                    │
              [LinkProvider]
                    ├── PageRepository で鮮度判定(方向別)
                    │
                    ├── outgoing の場合:
                    │   ├── fresh → DBから読む
                    │   ├── check → Wikipedia APIでtouched確認
                    │   │           └── 変更なし → fresh扱い
                    │   │           └── 変更あり → 再取得
                    │   └── missing → APIから取得してDBに保存
                    │
                    └── incoming の場合:
                        ├── fresh → DBから読む
                        └── missing → APIから取得してDBに追加(既存は削除しない)
```

---

## 11. Repository — Laravelの「標準にない」フォルダ②

### Repositoryパターンとは

**データベースへのアクセスを専用のクラスに閉じ込める**パターン。ControllerやServiceから直接Eloquentモデルを操作する代わりに、Repositoryを経由する。

### なぜEloquentがあるのにRepositoryが必要なのか

小さなアプリならEloquentモデルを直接使えばいい。しかし本アプリでは以下の理由でRepositoryを採用している。

1. **複雑なクエリの再利用**: 「ページをupsertしてIDマップを返す」という操作は何箇所からも呼ばれる
2. **トランザクション管理**: 「古いリンクを削除して新しいリンクを挿入」はトランザクションでまとめる必要がある
3. **テスト容易性**: Repositoryをモック（偽物）に差し替えれば、DBなしでServiceのテストができる

### `app/Repositories/` の3つのクラス

#### `PageRepository.php`

```php
class PageRepository
{
    // 複数タイトルのページを一括で作成または取得（upsert）
    // 戻り値: ['日本' => 1, '東京' => 2, ...]
    public function ensurePages(array $titles): array { ... }

    // キャッシュ鮮度判定: fresh / check / stale / missing
    public function getFreshnessMap(array $pageIds): array { ... }

    // メタ情報の更新
    public function upsertMeta(int $pageId, ?Carbon $wikiTouchedAt, int $linkCount): void { ... }
}
```

#### `LinkRepository.php`

```php
class LinkRepository
{
    // source_id → [target_id, ...] を一括取得
    public function getOutgoingTargetIds(array $sourceIds): array { ... }

    // あるページの全リンクを置き換え（トランザクション内で実行）
    public function replaceOutgoingLinks(int $sourceId, array $targetIds): void { ... }
}
```

#### `SearchHistoryRepository.php`

```php
class SearchHistoryRepository
{
    // 探索結果の記録（トランザクション内で history + steps を同時挿入）
    public function record(
        int $startId, int $goalId, array $pathPageIds,
        bool $found, int $durationMs, ...
    ): int { ... }
}
```

---

## 12. なぜ標準にないフォルダを作るのか — レイヤードアーキテクチャ

### 「太ったController」問題

初心者がよくやりがちな書き方：

```php
// ❌ 全部Controllerに書いてしまう（太ったController）
class PathFinderController
{
    public function stream(Request $request)
    {
        // URLバリデーション (30行)
        // Wikipedia APIでタイトル正規化 (50行)
        // BFSアルゴリズム (200行)
        // DBにリンクキャッシュ (100行)
        // 履歴記録 (30行)
        // SSEレスポンス生成 (50行)
    }
}
```

問題点：
- 1メソッドが500行以上になり、読めない・直せない
- BFSアルゴリズムを別のコンテキスト（CLIコマンドなど）で再利用できない
- テストが困難（HTTP経由でしか実行できない）

### レイヤー分離後

```php
// ✅ 各層が自分の仕事だけをする
Controller → Service → Repository → Model/DB
                ↓
           API Client → Wikipedia API
```

| 層 | 責務 | 変更理由 |
|----|------|---------|
| Controller | HTTPの入出力 | URLが変わった時 |
| Service | ビジネスルール | アルゴリズムが変わった時 |
| Repository | データアクセス | テーブル構造が変わった時 |
| Model | テーブル定義 | カラムが増えた時 |

**各層は「自分の上下の層」だけを知っていればよい。** ControllerはRepositoryを直接呼ばないし、RepositoryはHTTPリクエストを知らない。

### 本アプリのフォルダまとめ

| フォルダ | Laravel標準？ | 用途 |
|---------|-------------|------|
| `app/Http/Controllers/` | ✅ 標準 | HTTPリクエスト処理 |
| `app/Models/` | ✅ 標準 | テーブル定義、リレーション |
| `app/Providers/` | ✅ 標準 | DIコンテナ設定 |
| `app/Services/` | ❌ 独自追加 | ビジネスロジック |
| `app/Repositories/` | ❌ 独自追加 | DB操作の抽象化 |
| `config/finder.php` | △ 場所は標準、ファイルは独自 | アプリ固有設定 |

> 他にも、よく追加される独自フォルダには `app/Events/`, `app/Listeners/`, `app/Jobs/`, `app/Mail/`, `app/Notifications/`, `app/Policies/` などがある。Laravel自体が `php artisan make:event` 等のコマンドでこれらのフォルダを生成してくれるものも多い。

---

## 13. Eloquent ORM の実践テクニック

### 13.1 Eager Loading（N+1問題の回避）

```php
// ❌ N+1問題（履歴100件あると、101回のSQLが発行される）
$histories = SearchHistory::all();
foreach ($histories as $h) {
    echo $h->startPage->title;  // 1件ごとにSELECT * FROM pages WHERE id = ?
}

// ✅ Eager Loading（2回のSQLで済む）
$histories = SearchHistory::with(['startPage', 'goalPage'])->get();
// SQL 1: SELECT * FROM search_history
// SQL 2: SELECT * FROM pages WHERE id IN (1, 2, 3, ...)
```

本アプリでは `HistoryController@index` と `StatsController@index` で `with()` を使っている。

### 13.2 一括操作

```php
// insertOrIgnore: 既存があってもエラーにしない
Page::insertOrIgnore([
    ['title' => '日本'],
    ['title' => '東京'],
]);

// updateOrCreate: あれば更新、なければ作成（upsert）
PageMeta::updateOrCreate(
    ['page_id' => $pageId],        // 検索条件
    ['fetched_at' => Carbon::now()] // 更新内容
);
```

### 13.3 クエリビルダ vs Eloquent

本アプリでは両方を使い分けている：

```php
// Eloquent（モデルのインスタンスが必要な場合）
$history = SearchHistory::with(['startPage'])->findOrFail($id);

// クエリビルダ（集計や結合など、モデル不要の場合）
$totalLinks = DB::table('links')->count();

$topHubs = DB::table('search_path_steps as s')
    ->join('search_history as h', 'h.id', '=', 's.history_id')
    ->join('pages as p', 'p.id', '=', 's.page_id')
    ->select('p.title', DB::raw('COUNT(*) as appearances'))
    ->groupBy('p.id', 'p.title')
    ->orderByDesc('appearances')
    ->limit(20)
    ->get();
```

### 13.4 ページネーション

Laravelにはページネーション機能が組み込まれている：

```php
// Controller
$histories = $query->paginate(20)->appends($request->query());

// View
{!! $histories->links() !!}  {{-- ページネーションリンクを自動生成 --}}
```

`appends($request->query())` は、ページネーションリンクに現在のクエリパラメータ（`?filter=found` など）を引き継ぐ。

---

## 14. SSE（Server-Sent Events） — リアルタイム通信

### SSEとは

サーバーからクライアントへ **一方向** にリアルタイムでデータを送るHTTPベースの仕組み。WebSocketより軽量で、本アプリのように「サーバーが進捗を報告する」用途に最適。

### サーバー側（Laravel）

```php
return response()->stream(function () {
    // 出力バッファリングを無効化（リアルタイム送信に必須）
    while (ob_get_level() > 0) { @ob_end_flush(); }

    // SSEイベントの送信関数
    $send = function (string $event, array $data) {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
    };

    // 処理を実行しながらイベントを送信
    $send('connected', ['start' => '日本', 'goal' => 'ヘミングウェイ']);
    // ... 探索処理 ...
    $send('result', ['clicks' => 3, 'path' => [...]]);
    $send('done', []);

}, 200, [
    'Content-Type'      => 'text/event-stream; charset=UTF-8',
    'Cache-Control'     => 'no-cache, no-transform',
    'X-Accel-Buffering' => 'no',         // Nginx のバッファリングを無効化
    'Connection'        => 'keep-alive',
]);
```

### クライアント側（JavaScript）

```javascript
const source = new EventSource('/find-path/stream?start_url=...&goal_url=...');

source.addEventListener('connected', (ev) => {
    const data = JSON.parse(ev.data);
    console.log('接続完了:', data);
});

source.addEventListener('result', (ev) => {
    const data = JSON.parse(ev.data);
    renderPath(data);
});

source.addEventListener('done', () => {
    source.close();  // 必ず閉じる
});

source.onerror = () => {
    source.close();
    showError('接続が切断されました');
};
```

### コールバックパターン

本アプリでは、Service層のイベントをController層に伝えるために **コールバック** を使っている：

```php
// Controller側: コールバックを設定
$finder->setProgressCallback(function (string $event, array $payload) use ($send) {
    $send($event, $payload);  // SSEイベントとして送信
});

// Service側: 処理の途中でコールバックを呼ぶ
private function emit(string $event, array $payload = []): void
{
    if ($this->onProgress) {
        ($this->onProgress)($event, $payload);
    }
}
```

この設計により、Service層はSSEの存在を知らない。将来CLIコマンドから探索を実行する場合でも、コールバックの中身を変えるだけでよい。

---

## 15. HTTPクライアント（Http ファサード）

本アプリでは Wikipedia API への通信に Laravel の `Http` ファサードを使っている。内部的には GuzzleHTTP を使っているが、Laravel流の簡潔なAPIで呼べる。

### 15.1 基本的な使い方

```php
use Illuminate\Support\Facades\Http;

// GET リクエスト
$response = Http::get('https://ja.wikipedia.org/w/api.php', [
    'action' => 'query',
    'titles' => '日本',
    'format' => 'json',
]);

// レスポンスの扱い
$response->ok();           // HTTPステータスが2xxかどうか
$response->status();       // ステータスコード（200等）
$response->json();         // JSONをPHP配列に変換
$response->json('query.pages.0');  // ドット記法で深い階層にアクセス
$response->body();         // 生のレスポンスボディ
$response->serverError();  // 5xxかどうか
```

### 15.2 オプション付きリクエスト

```php
$response = Http::withHeaders(['User-Agent' => 'WikiPathFinder/1.0'])
    ->timeout(15)          // タイムアウト（秒）
    ->get($url, $params);
```

メソッドチェーンで設定を積み重ねる。本アプリの `WikipediaApiClient` では全リクエストにUser-Agentとタイムアウトを設定している。

### 15.3 ドット記法アクセス — `$response->json('query.pages.0')`

Wikipedia APIのレスポンスが以下の構造だとする：

```json
{ "query": { "pages": [ { "title": "日本", "ns": 0 } ] } }
```

`$response->json('query.pages.0')` で `{ "title": "日本", "ns": 0 }` に一発でアクセスできる。第2引数はデフォルト値：

```php
$response->json('query.pages', [])  // pagesがなければ空配列
```

### 15.4 Guzzle を直接使う場合との比較

```php
// Laravel Http ファサード（簡潔）
$response = Http::timeout(15)->get($url, $params);

// Guzzle 直接（冗長）
$client = new \GuzzleHttp\Client();
$response = $client->request('GET', $url, [
    'timeout' => 15,
    'query' => $params,
]);
$data = json_decode($response->getBody()->getContents(), true);
```

---

## 16. ロギング

### 16.1 基本

```php
use Illuminate\Support\Facades\Log;

Log::debug('デバッグ情報');
Log::info('情報メッセージ');
Log::warning('[finder] suggest failed', ['message' => $e->getMessage()]);
Log::error('[finder] stream exception', ['line' => $e->getLine()]);
```

### 16.2 コンテキスト配列

第2引数に連想配列を渡すと、ログに構造化データが付加される：

```php
Log::error('[finder] stream exception', [
    'message' => $e->getMessage(),
    'line'    => $e->getLine(),
    'file'    => $e->getFile(),
]);
```

出力例（`storage/logs/laravel.log`）：

```
[2026-05-03 12:34:56] local.ERROR: [finder] stream exception {"message":"Connection timed out","line":123,"file":"WikipediaApiClient.php"}
```

### 16.3 ログレベルの使い分け

| レベル | 本アプリでの用途 |
|---|---|
| `debug` | 開発中の一時的な確認用 |
| `info` | 正常系の記録（正規化でページが見つからなかった等） |
| `warning` | 想定内の異常（API失敗、部分エラー、履歴記録失敗） |
| `error` | 想定外の異常（例外キャッチ） |

`.env` の `LOG_LEVEL` でフィルタできる。本番では `warning` 以上にすることが多い。

---

## 17. サービスコンテナと依存性注入

Laravelの中核機能。初学者は「あー、そんなのがあるんだ」程度の認識で大丈夫だが、理解するとLaravelの「魔法」の正体が見える。

### 17.1 サービスコンテナとは

クラスのインスタンスを管理する「箱」のような仕組み。Laravelはあらゆる箇所でこのコンテナを使ってオブジェクトを生成・取り出している。

### 17.2 自動依存性注入（Auto DI）

コントローラのメソッドに型ヒントを書くと、Laravelが自動的にインスタンスを作って渡してくれる：

```php
public function index(Request $request): View
//                    ↑ Laravelが自動でDIする
```

`Request $request` と書くだけで、現在のHTTPリクエストのRequestインスタンスが渡される。`new Request()` を自分で書く必要はない。これが依存性注入（DI: Dependency Injection）。

### 17.3 本アプリでの実例

`WikipediaPathFinder` のコンストラクタで言語（`$lang`）が必要なので、コントローラ側で明示的に `new` している：

```php
$finder = new WikipediaPathFinder($start['lang'], $depth);
```

リクエストごとに言語が変わるため、DIコンテナに登録するよりも `new` が適切な例。

もし言語が固定なら、サービスプロバイダで登録できる：

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->bind(WikipediaPathFinder::class, function ($app) {
        return new WikipediaPathFinder('ja');
    });
}

// コントローラで自動DIされる
public function stream(Request $request, WikipediaPathFinder $finder) { ... }
```

### 17.4 コールバック注入 — 本アプリのIoC実例

```php
$finder->setProgressCallback(function (string $event, array $payload) use ($send) {
    $send($event, $payload);
});
```

Service層がSSEのことを知らずに、Controller側から「通知方法」を注入する。これは**Inversion of Control（制御の反転）**の小さな実例。将来CLIコマンドから探索を呼ぶ場合：

```php
// Artisanコマンドから呼ぶ場合（SSEではなくコンソール出力）
$finder->setProgressCallback(function (string $event, array $payload) {
    $this->info("[$event] " . json_encode($payload));
});
```

**同じServiceクラスをWebからもCLIからも呼べる** — これがService層を分離する最大のメリット。

---

## 18. ファサードとは

`Log::info()` や `Http::get()` の `Log::` `Http::` の部分が**ファサード**。

### 18.1 ファサードの正体

実は静的メソッドではない。**サービスコンテナ経由で実体クラスのインスタンスを取り出して、メソッドを呼んでいる**だけ：

```php
Log::info('hello');
// ↓ 内部的にはこれと等価
app('log')->info('hello');
```

### 18.2 本アプリで使っているファサード

| ファサード | 実体 | 使用箇所 |
|---|---|---|
| `Log` | `Illuminate\Log\LogManager` | 各所のログ出力 |
| `Http` | `Illuminate\Http\Client\Factory` | `WikipediaApiClient` |
| `DB` | `Illuminate\Database\DatabaseManager` | `StatsController` のクエリビルダ、トランザクション |

### 18.3 ファサード vs DI

| ファサード | DI |
|---|---|
| 短く書ける | テストしやすい |
| `use` 文を追加するだけ | コンストラクタ引数で受け取る |
| 依存関係が暗黙的 | 依存関係が明示的 |

実務では「使いやすいので使う」と割り切ることが多い。本アプリでも `Http`, `Log`, `DB` をファサードで呼んでいる。

---

## 19. ミドルウェア

リクエスト/レスポンスがコントローラに届く前後に処理を挟む仕組み。本アプリでは自作していないが、**Laravelデフォルトのミドルウェアが裏で動いている**。

### 19.1 Laravelが自動的に適用するミドルウェア

| ミドルウェア | 役割 |
|---|---|
| `EncryptCookies` | Cookieの暗号化 |
| `StartSession` | セッション開始 |
| `ValidateCsrfToken` | CSRFトークン検証（POSTリクエスト時） |
| `TrimStrings` | 入力の前後空白除去 |
| `HandleCors` | CORS処理 |

### 19.2 自作ミドルウェアの例

もし「管理者だけ統計ページを見られるようにしたい」なら：

```php
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->is_admin) {
            abort(403);
        }
        return $next($request);  // 次のミドルウェア or コントローラへ
    }
}
```

ルートに適用：

```php
Route::get('/stats', [StatsController::class, 'index'])->middleware('admin');
```

### 19.3 本アプリでの注意点

SSEの `/find-path/stream` はGETリクエストなのでCSRF検証の対象外。もしPOSTに変更する場合はCSRFトークンの送信が必要になる。

---

## 20. Artisanコマンド

LaravelのCLIツール。開発中に頻繁に使う。

### 20.1 本アプリで使う主要コマンド

```bash
# マイグレーション（テーブル作成）
php artisan migrate

# マイグレーションをやり直す（全テーブル再作成）
php artisan migrate:fresh

# 開発サーバー起動
php artisan serve

# ルート一覧の確認
php artisan route:list

# キャッシュクリア
php artisan config:clear
php artisan cache:clear

# テスト実行
php artisan test
```

### 20.2 自作コマンドの例

もしCLIから探索を実行したいなら：

```php
// app/Console/Commands/FindPathCommand.php
class FindPathCommand extends Command
{
    protected $signature = 'find:path {start} {goal}';
    protected $description = 'Wikipedia最短経路をCLIで探索';

    public function handle(): int
    {
        $finder = new WikipediaPathFinder('ja');
        $result = $finder->findPath(
            $this->argument('start'),
            $this->argument('goal')
        );
        $this->info("Clicks: {$result['clicks']}");
        foreach ($result['path'] as $title) {
            $this->line("  - {$title}");
        }
        return Command::SUCCESS;
    }
}
```

```bash
php artisan find:path 日本 アーネスト・ヘミングウェイ
```

**これがService層を分離していると再利用が効く好例。** 同じ `WikipediaPathFinder` をWebからもCLIからも呼べる。

---

## 21. フロントエンド資産の管理

### 本アプリの二重構成

本アプリでは、フロントエンド資産に2つの管理方法が混在している。

#### ① Vite経由（`resources/css/`, `resources/js/`）

Laravelの標準的なフロントエンド管理。Tailwind CSS等をビルドし、`public/build/` に出力する。Laravelの初期ページ（`welcome.blade.php`）で使われている。

```blade
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

#### ② 直接配置（`public/css/`, `public/js/`）

本アプリの探索ページなどでは、ビルドツールを通さずに直接CSSとJSを配置している。

```blade
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<script src="{{ asset('js/finder.js') }}"></script>
```

**なぜ直接配置を選んだのか?**: 探索ページではTailwindを使わず、手書きCSSで完結している。ViteやReactなどのフレームワークを使わないVanilla JSで十分なため、ビルドの複雑さを避けている。

### `asset()` ヘルパー

```php
asset('css/app.css')
// → http://localhost/css/app.css（開発時）
// → https://example.com/css/app.css（本番時）
```

`public/` ディレクトリを基準にしたURLを生成する。`APP_URL` の設定に応じて自動的にドメインが変わる。

---

## 22. テスト

### テストの配置場所

```
tests/
├── Feature/        ← 機能テスト（HTTPリクエストを通したテスト）
│   └── ExampleTest.php
├── Unit/           ← ユニットテスト（個別クラスのテスト）
│   └── ExampleTest.php
└── TestCase.php    ← テストの基底クラス
```

### テストの実行

```bash
php artisan test          # 全テスト実行
php artisan test --filter=ExampleTest  # 特定のテストだけ
```

### テスト用のDB設定 (`phpunit.xml`)

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

テスト時はインメモリSQLiteを使い、テストごとにDBが初期化される。

---

## 23. 本アプリで使われているLaravelの機能一覧

| 機能 | 使用箇所 | 解説 |
|------|---------|------|
| **ルーティング** | `routes/web.php` | URLとControllerの紐づけ |
| **名前付きルート** | `->name('finder.index')` | Blade内で `route()` で参照 |
| **ルートパラメータ** | `{id}`, `->whereNumber()` | URLに含まれる動的な値 |
| **Eloquent ORM** | `app/Models/*.php` | DB操作をオブジェクト指向で |
| **リレーション** | `hasMany`, `belongsTo`, `hasOne` | テーブル間の関連を定義 |
| **Eager Loading** | `::with([...])` | N+1問題の防止 |
| **マイグレーション** | `database/migrations/` | テーブル定義のバージョン管理 |
| **Bladeテンプレート** | `resources/views/` | HTMLテンプレートエンジン |
| **レイアウト継承** | `@extends`, `@section`, `@yield` | 共通レイアウトの再利用 |
| **スタック** | `@push`, `@stack` | CSS/JSの追加 |
| **ページネーション** | `->paginate()`, `links()` | 自動ページ分割 |
| **Configファイル** | `config/finder.php` | アプリ固有の設定 |
| **環境変数** | `.env`, `env()` | 環境ごとの値管理 |
| **HTTPクライアント** | `Http::get()` | 外部API（Wikipedia）への通信 |
| **StreamedResponse** | `response()->stream()` | SSEの実装 |
| **ファサード** | `DB::`, `Log::`, `Route::` | よく使うクラスへの簡易アクセス |
| **ヘルパー関数** | `config()`, `asset()`, `route()` | よく使う操作のショートカット |
| **トランザクション** | `DB::transaction()` | 複数テーブルへの一貫した書き込み |
| **Carbon** | `Carbon::now()`, `->diffInHours()` | 日時操作 |
| **クエリビルダ** | `DB::table()->join()->select()` | 複雑なSQLクエリ |
| **テスト** | `tests/`, `phpunit.xml` | 自動テスト基盤 |

---

## 24. 本アプリで採用しなかったLaravel機能

「使わなかった」というのも勉強になるので、何があるか並べる。

| 機能 | 使わなかった理由 | いつ使うか |
|---|---|---|
| 認証 (Breeze/Jetstream) | 認証不要 | ログイン機能が要るとき |
| キュー (Job) | SSEでリアルタイム処理 | 重い処理をバックグラウンド化したいとき |
| イベント・リスナー | 他システムへの通知なし | 「投稿されたらメール送る」などのフック |
| ブロードキャスト (Reverb) | SSEで足りた | 双方向通信が要るとき |
| Inertia / Livewire | 古典的なBlade+JSで足りた | SPA的なUIを作るとき |
| Cache | 独自キャッシュ層を実装 | 標準的なキャッシュが欲しいとき |
| Mail | メール送信なし | 通知メールを送るとき |
| Notification | 通知なし | 複数チャネル（メール+Slack+SMS）で通知したいとき |
| File Storage | ファイル保存なし | 画像アップロード等を扱うとき |
| Policy / Gate | 認可なし | リソースごとの権限管理 |
| API Resource | JSONを手で組み立てた | RESTful APIで構造化レスポンスを返すとき |
| Form Request | コントローラ内で検証 | バリデーションが複雑になったとき |

これらは**必要になったら学ぶ**方針でOK。最初から全部覚える必要はない。

---

## 25. Laravelをさらに学ぶための道筋

### 25.1 公式ドキュメント

https://laravel.com/docs （英語が原典、日本語訳もあり）

特に最初に読むべき項目：
1. Routing
2. Controllers
3. Requests / Responses
4. Views (Blade)
5. Eloquent: Getting Started
6. Database: Migrations
7. Validation
8. Authentication

### 25.2 おすすめ学習順

```
[基礎]
1. ルーティング + コントローラ + Blade で静的ページ
2. フォーム + バリデーション
3. Eloquent + マイグレーション で簡単なCRUD
4. 認証（Laravel Breezeで一発導入）

[中級]
5. リレーション（hasMany / belongsTo）
6. ミドルウェア自作
7. Form Request クラス
8. テスト（PHPUnit）

[応用]
9. キュー / ジョブ
10. イベント / リスナー
11. Service層・Repository層の自前設計
12. Service Provider
13. ブロードキャスト（WebSocket）
```

### 25.3 本アプリから次に拡張するなら

腕試しとして、以下の拡張を試してみると勉強になる（難易度順）：

1. **`php artisan find:path 日本 月` のCLIコマンド化**（Artisanコマンド体験）
2. **ユーザー登録 + 自分の検索履歴を見られるように**（認証 + 認可体験）
3. **同じペアの再検索をキャッシュ化**（Cacheファサード体験）
4. **重い検索をジョブキューに投げる**（キュー体験、SSEとの組み合わせは難しいが面白い）
5. **REST APIの追加**（API Resource、JSON構造化、トークン認証体験）

---

## 付録A: 開発を始めるためのコマンド

```bash
# 1. 依存パッケージのインストール
composer install
npm install

# 2. 環境設定
cp .env.example .env
php artisan key:generate

# 3. データベースの作成（SQLiteの場合）
touch database/database.sqlite
php artisan migrate

# 4. 開発サーバーの起動
php artisan serve

# 5. （別ターミナルで）Viteの起動（welcome.blade.phpのみ使用）
npm run dev
```

## 付録B: よくある疑問

### Q: `app/Http/Controllers/Controller.php` が空っぽだけど必要？

A: Laravel標準の基底コントローラ。本アプリのコントローラはこれを継承していないが（`extends Controller` がない）、残しておいても問題ない。ミドルウェアの共通設定などに使う場合がある。

### Q: `$timestamps = false` が全モデルについているのはなぜ？

A: Laravelは標準で `created_at` と `updated_at` カラムを自動管理するが、本アプリでは独自の日時カラム（`searched_at`, `fetched_at`）を使っているため、自動管理を無効にしている。

### Q: ServiceやRepositoryは `php artisan make:xxx` で作れない？

A: 作れない。手動で `app/Services/` フォルダを作り、PHPファイルを置く。名前空間（`namespace App\Services;`）を正しく設定すれば、Laravelのオートローダーが自動的に読み込む（PSR-4）。

### Q: Repository無しで直接Eloquentを使ってもよい？

A: 小〜中規模のアプリなら全く問題ない。本アプリでは、「キャッシュ鮮度の判定」や「リンクの一括置換」など、単純なCRUDを超えるDB操作があるため、Repositoryに分離する価値がある。

### Q: なぜWebSocketではなくSSEを使っている？

A: 本アプリはサーバー→クライアントの一方向通信で十分（クライアントは探索開始後、結果を待つだけ）。SSEはHTTPの上に載るシンプルな仕組みで、Laravel側に特別なパッケージが不要。WebSocketは双方向通信が必要な場合（チャットなど）に使う。

---

## おわりに

Laravelは「やろうと思えば何でもできる」フレームワーク。本アプリで触ったのは機能の一部だが、**ルーティング → コントローラ → Service → Repository → Model → Blade** というレイヤー分離の感覚さえ掴めれば、あとは必要に応じて機能を追加していくだけ。

特に **Service層** と **Repository層** は標準にないだけに見落とされがちだが、本アプリのような「ロジックがそれなりに複雑なアプリ」では事実上必須になる考え方。Fat Controllerを避けるための最初の一歩として、習得する価値は大きい。

コードウォークスルー (`code-walkthrough.md`) では、各ファイルを実際のコードを引用しながらさらに詳しく解説している。
