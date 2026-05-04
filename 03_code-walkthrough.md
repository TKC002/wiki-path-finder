# コードウォークスルー — 本アプリの各ファイルを読み解く

このドキュメントでは、Wikipedia 6クリック挑戦アプリのソースコードを1ファイルずつ追いながら、「なぜこの書き方になっているか」「Laravelのどの機能を使っているか」を解説します。指南書 (`laravel-guide.md`) の内容を実コードに紐付けて理解するための実践編です。

---

## 目次

1. [`routes/web.php` — URLとコードの紐付け](#1-routeswebphp)
2. [`app/Http/Controllers/PathFinderController.php` — 探索の司令塔](#2-pathfindercontroller)
3. [`app/Http/Controllers/HistoryController.php` — 履歴のCRUD](#3-historycontroller)
4. [`app/Http/Controllers/StatsController.php` — 統計集計](#4-statscontroller)
5. [`app/Services/WikipediaPathFinder.php` — BFSエンジン](#5-wikipediapathfinder)
6. [`app/Services/WikipediaApiClient.php` — API通信層](#6-wikipediaapiclient)
7. [`app/Services/LinkProvider.php` — キャッシュ層](#7-linkprovider)
8. [`app/Repositories/` — DB操作の抽象化](#8-repositories)
9. [`app/Models/` — Eloquentモデル群](#9-models)
10. [`database/migrations/` — テーブル定義](#10-migrations)
11. [`resources/views/` — Bladeテンプレート](#11-views)
12. [`public/js/` — フロントエンドJS](#12-frontend-js)
13. [`config/finder.php` — 独自設定ファイル](#13-config)

---

## 1. `routes/web.php`

```php
Route::get('/', [PathFinderController::class, 'index'])->name('finder.index');
Route::get('/find-path/stream', [PathFinderController::class, 'stream'])->name('finder.stream');
Route::get('/suggest', [PathFinderController::class, 'suggest'])->name('finder.suggest');

Route::get('/history',      [HistoryController::class, 'index'])->name('history.index');
Route::get('/history/{id}', [HistoryController::class, 'show'])->name('history.show')->whereNumber('id');

Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
```

### 1.1 全体構造

たった6行でアプリの全URLが定義されている。Laravelでは `routes/web.php` を開けば「このアプリにはどんな画面があるか」が即座に分かる。これが「規約の力」。

### 1.2 `[Class::class, 'method']` 記法

```php
Route::get('/', [PathFinderController::class, 'index'])
```

Laravel 9以降で推奨されている形式。古い書き方 `'PathFinderController@index'`（文字列指定）は型チェックが効かないので避けるべき。`PathFinderController::class` はPHPの定数で、フルクラス名の文字列（`'App\Http\Controllers\PathFinderController'`）を返す。IDEのリファクタリング機能も正しく動く。

### 1.3 `->name(...)` の効果

ルートに名前を付けると、URLのハードコードを完全に排除できる：

```blade
{{-- Bladeテンプレートで --}}
<a href="{{ route('history.show', $h->id) }}">詳細</a>

{{-- JavaScript内で --}}
window.FINDER_CONFIG = {
    streamUrl: '{{ route("finder.stream") }}',
};
```

**URLの構造が `/find-path/stream` から `/api/stream` に変わっても、`web.php` を1箇所直すだけでアプリ全体が追従する。** これが名前付きルートの最大の利点。

### 1.4 `{id}` と `->whereNumber('id')`

```php
Route::get('/history/{id}', ...)->whereNumber('id');
```

- `{id}` はURLパラメータ。`/history/42` なら `$id = 42` がコントローラに渡される
- `whereNumber('id')` は「idが数値でなければ404を返す」という制約。`/history/abc` はマッチしない
- この制約がないと、文字列がDBクエリに渡されて予期しないエラーになる可能性がある

### 1.5 GETだけで構成されている理由

全ルートがGET。RESTful設計ではデータ変更はPOST/PUT/DELETEだが、このアプリでは：

- `stream` → SSEの `EventSource` がGETしか対応していないため
- `suggest` → 検索なのでGETが自然
- 履歴・統計 → 表示のみ、データ変更なし

探索結果の**DB記録はSSEストリーム内で自動的に行われる**ため、別途POSTリクエストは不要。

---

## 2. `PathFinderController.php`

本アプリのHTTP層の中心。3つのアクションメソッドを持つ。

### 2.1 ファイルヘッダ

```php
namespace App\Http\Controllers;

use App\Repositories\SearchHistoryRepository;
use App\Services\WikipediaApiClient;
use App\Services\WikipediaPathFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

#### `use` 文の整理

| クラス | 出自 | 役割 |
|---|---|---|
| `SearchHistoryRepository` | 自作 Repository | 探索結果のDB記録 |
| `WikipediaApiClient` | 自作 Service | サジェスト用API通信 |
| `WikipediaPathFinder` | 自作 Service | 経路探索エンジン |
| `Request` | Laravel | HTTPリクエストオブジェクト |
| `Log` | Laravel ファサード | ログ出力 |
| `View` | Laravel | Bladeビューの戻り値型 |
| `StreamedResponse` | **Symfony** | SSE用ストリーミングレスポンス |

`StreamedResponse` だけ `Symfony` 名前空間なのに注目。**LaravelはSymfonyの上に構築されている**ため、HTTP基盤のクラスはSymfonyから借りている。

### 2.2 クラス宣言

```php
class PathFinderController
{
```

**`extends Controller` を書いていない。** Laravel 13では `Controller` ベースクラスは空なので、本アプリでは省略可能。ただし将来ミドルウェアを使う場合は継承した方が便利。

### 2.3 `index()` — 画面表示

```php
public function index(): View
{
    return view('finder');
}
```

最もシンプルなアクション。`view('finder')` は `resources/views/finder.blade.php` をレンダリングして返す。引数なしなので、Bladeテンプレートに渡す変数はない（JSの設定はBlade内で直接 `route()` を呼んでいる）。

### 2.4 `stream()` — SSEのメイン処理

ここが本アプリで最も技術的に密度が高い箇所。

```php
public function stream(Request $request): StreamedResponse
{
    $startUrl = (string) $request->query('start_url', '');
    $goalUrl = (string) $request->query('goal_url', '');
    $depth = (int) $request->query('depth', config('finder.default_max_depth_per_side', 3));
```

#### `$request->query()` の意味

| メソッド | 取得元 |
|---|---|
| `$request->query('key')` | URLクエリパラメータのみ（`?key=value`） |
| `$request->input('key')` | クエリ＋ボディ両方 |
| `$request->post('key')` | ボディのみ（POST） |

第2引数はデフォルト値。`(string)` キャストは「nullが来ても空文字にする」安全策。

#### `config()` でアプリ設定を読む

```php
config('finder.default_max_depth_per_side', 3)
```

`config/finder.php` の `default_max_depth_per_side` キーの値を読む。ファイルが見つからない場合のフォールバック値が第2引数の `3`。

```php
    return response()->stream(function () use ($startUrl, $goalUrl, $depth) {
```

#### `response()->stream()` — SSEの核心

コールバック関数を渡すと、**その関数内で `echo` した内容が逐次クライアントに送信される**仕組み。通常のレスポンスは「全部計算してから返す」が、ストリーミングは「計算しながら少しずつ返す」。

`use ($startUrl, $goalUrl, $depth)` はクロージャに外部変数を取り込む構文。**JavaScriptと違い、PHPのクロージャは明示的に `use` で変数をキャプチャする必要がある。**

#### バッファリング対策（SSEで最も重要な部分）

```php
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', '1');
```

PHPは出力をバッファに溜め込んでから一気に送る性質がある。SSEではこれだと**進捗イベントが最後にまとめて届いてしまう**ので、バッファを完全に無効化する：

- `ob_get_level()` で現在のバッファ階層数を取得し、全て `ob_end_flush()` で吐き出す
- `zlib.output_compression` を切る（gzip圧縮があると蓄積される）
- `implicit_flush` を有効化（echo毎に自動flush）

`@` プレフィックスはエラー抑制演算子。環境によっては設定変更が拒否されるが、エラーを出さずに続行する。

#### クライアント切断検知とタイムアウト

```php
        ignore_user_abort(false);
        $hardTimeoutSec = 540;
        set_time_limit(0);
        $absoluteDeadline = microtime(true) + $hardTimeoutSec;
```

- `ignore_user_abort(false)` — ブラウザを閉じたら即座にPHPプロセスを停止（デフォルトは停止しない）
- `set_time_limit(0)` — PHPのスクリプト実行時間制限を無制限に設定。タイムアウトはアプリレベル（`$absoluteDeadline`）で制御する
- `$absoluteDeadline` — 540秒のアプリレベルタイムアウト。コールバック内で毎回チェックし、超過時は例外を投げる

#### シャットダウンハンドラ

```php
        $sendShutdown = function () use (&$alreadySentDone) {
            if (!empty($alreadySentDone)) return;
            echo "event: error\n";
            echo 'data: ' . json_encode(['message' => '探索が予期せず終了しました'], ...) . "\n\n";
            echo "event: done\ndata: {}\n\n";
            @ob_flush(); @flush();
        };
        register_shutdown_function($sendShutdown);
```

PHPが異常終了（メモリ不足、タイムアウト等）した場合でも、クライアントに `error` と `done` イベントを送る。**これがないと、ブラウザ側で「永遠に待ち続ける」状態になる。** `&$alreadySentDone` は参照渡しで、正常終了時に `true` にセットして二重送信を防ぐ。

#### SSEプロトコルの実装

```php
        $send = function (string $event, array $data) use (&$alreadySentDone) {
            if ($event === 'done') { $alreadySentDone = true; }
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            @ob_flush();
            @flush();
            if (connection_aborted()) {
                throw new \RuntimeException('Client disconnected');
            }
        };
```

SSEはテキストベースのプロトコル。1メッセージの形式：

```
event: layer_start
data: {"depth":1,"frontier_size":487}

```

**最後の空行（`\n\n`）が必須。** これが1メッセージの終端を表す。

`JSON_UNESCAPED_UNICODE` は日本語をエスケープせずそのまま出力するオプション（`\u65e5\u672c` ではなく `日本` と出る）。

`ob_flush()` と `flush()` の2段階flush：
- `ob_flush()` — PHPの出力バッファを吐き出す
- `flush()` — Webサーバーレベルのバッファも吐き出す
- 両方呼ばないとブラウザに届かないことがあるため、両方呼ぶのが定石

`connection_aborted()` — クライアントが切断されたかチェック。切断されていたら例外を投げて探索を中止。これにより**ユーザーがページを閉じたら無駄なAPI呼び出しが止まる**。

#### コールバック注入（依存性注入の実例）

```php
            $finder = new WikipediaPathFinder($start['lang'], $depth);
            $finder->setProgressCallback(function (string $event, array $payload) use ($send, $absoluteDeadline) {
                if (microtime(true) > $absoluteDeadline) {
                    throw new \RuntimeException('探索がタイムアウトしました');
                }
                $send($event, $payload);
            });
            $result = $finder->findPath($start['title'], $goal['title']);
```

`setProgressCallback` で「進捗が起きたら呼んでほしい関数」をServiceに渡す。Service層はSSEの存在を知らず、**ただ「何かイベントが起きた」と通知するだけ**。Controller側がそれを受け取って `$send()` でSSEに変換する。

これは**Inversion of Control（制御の反転）**の好例：
- Service層がControllerに依存しない
- 将来WebSocketに切り替えてもService層は無変更
- テスト時は何もしないクロージャを渡せる

コールバック内でタイムアウトもチェックしている。これにより、探索の各ステップ（リンク取得のたび）でタイムアウトを確認できる。

#### 履歴記録ヘルパー

```php
    private function recordSuccessfulSearch(...): int {
        $repo = new SearchHistoryRepository();
        return $repo->record($startId, $goalId, $pathIds, true, ...);
    }

    private function recordFailedSearch(...): void {
        try {
            $pageRepo = new \App\Repositories\PageRepository();
            $idMap = $pageRepo->ensurePages([$startTitle, $goalTitle]);
            // ...
            $repo = new SearchHistoryRepository();
            $repo->record($startId, $goalId, [], false, ...);
        } catch (\Throwable $e) {
            Log::warning('[finder] failed to record failed search', ...);
        }
    }
```

成功時と失敗時で処理が異なる。失敗時は `try-catch` で囲っている — **「履歴記録の失敗」のために探索結果のSSEレスポンスが壊れないように**する防御的プログラミング。

### 2.5 `suggest()` — サジェストAPI

```php
    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $lang = (string) $request->query('lang', 'ja');
        if (!preg_match('/^[a-z-]{2,12}$/', $lang)) { $lang = 'ja'; }
        if ($q === '') { return response()->json(['suggestions' => []]); }

        try {
            $api = new WikipediaApiClient($lang);
            $suggestions = $api->suggest($q, 10);
            return response()->json(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            Log::warning('[finder] suggest failed', ...);
            return response()->json(['suggestions' => []]);
        }
    }
```

シンプルなJSONエンドポイント。注目点：

- **言語コードのバリデーション**: `preg_match('/^[a-z-]{2,12}$/', $lang)` で不正な値を弾く。SQLインジェクションではないが、**URLに使われる値は常にバリデーションするのが鉄則**
- **エラー時に空配列を返す**: 例外が起きてもフロントエンドが壊れないように、正常なJSON構造を返す
- **Serviceへの委譲**: Wikipedia APIの呼び出しは `WikipediaApiClient` に任せ、Controllerは結果の受け渡しだけ

---

## 3. `HistoryController.php`

典型的なLaravelの「表示系」コントローラ。CRUD（Create/Read/Update/Delete）のうちReadだけを担当。

### 3.1 `index()` — 一覧表示

```php
    public function index(Request $request): View
    {
        $perPage = 20;
        $query = SearchHistory::with(['startPage', 'goalPage'])
            ->orderByDesc('searched_at');
```

#### `::with()` — Eager Loading

これが**N+1問題を回避する**ための最も重要なテクニック。

```php
// ❌ N+1問題（履歴100件あると、1 + 100 + 100 = 201回のSQLが走る）
$histories = SearchHistory::all();  // SQL 1回
foreach ($histories as $h) {
    echo $h->startPage->title;     // SQL 100回（1件ずつ取得）
    echo $h->goalPage->title;      // SQL 100回
}

// ✅ Eager Loading（3回のSQLで済む）
$histories = SearchHistory::with(['startPage', 'goalPage'])->get();
// SQL 1: SELECT * FROM search_history
// SQL 2: SELECT * FROM pages WHERE id IN (1, 2, 3, ...)  ← startPage用
// SQL 3: SELECT * FROM pages WHERE id IN (4, 5, 6, ...)  ← goalPage用
```

`with()` に渡す文字列 `'startPage'` は、`SearchHistory` モデルで定義したリレーションメソッド名。

#### フィルタ処理

```php
        $filter = $request->query('filter', 'all');
        if ($filter === 'found') {
            $query->where('found', true);
        } elseif ($filter === 'failed') {
            $query->where('found', false);
        }
```

`$query` はEloquentのクエリビルダ。**条件分岐で `where` を追加していく「条件付きクエリ」パターン**。最初にベースクエリを作り、条件に応じてフィルタを足す。

#### ページネーション

```php
        $histories = $query->paginate($perPage)->appends($request->query());
```

- `paginate(20)` — 20件ずつにページ分割。SQLの `LIMIT 20 OFFSET 0` が自動生成される
- `appends($request->query())` — ページネーションリンクに現在のクエリパラメータを引き継ぐ。`?filter=found` で2ページ目に行くと `?filter=found&page=2` になる

### 3.2 `show()` — 詳細表示

```php
    public function show(int $id): View
    {
        $history = SearchHistory::with([
            'startPage', 'goalPage', 'steps.page',
        ])->findOrFail($id);
```

#### `findOrFail($id)` — 見つからなければ404

`find($id)` は見つからないと `null` を返すが、`findOrFail($id)` は自動的に404レスポンスを返す。**コントローラでnullチェックを書く手間が省ける。**

#### ネストされたEager Loading: `'steps.page'`

`steps` は `SearchHistory` から `SearchPathStep` へのリレーション。`steps.page` と書くと、**さらにその先の `SearchPathStep` → `Page` リレーションもEager Loadする**。ドット区切りで何段でもネストできる。

```php
        $finder = new WikipediaPathFinder('ja');
        $pathSteps = $history->steps->map(fn ($s) => [
            'title' => $s->page->title,
            'url'   => $finder->titleToUrl($s->page->title),
        ])->all();
```

#### `->map()` — コレクション変換

Eloquentの `->get()` や リレーション参照で返るのは `Collection` オブジェクト。`map()` は各要素を変換した新しいコレクションを返す（JavaScriptの `Array.map()` と同じ概念）。

`fn ($s) => [...]` はPHP 7.4以降のアロー関数。`function ($s) { return [...]; }` の短縮形。

---

## 4. `StatsController.php`

統計ダッシュボード。Eloquentとクエリビルダを使い分ける好例。

### 4.1 Eloquentで済む場合

```php
$totalSearches = SearchHistory::count();
$foundSearches = SearchHistory::where('found', true)->count();
```

シンプルな集計はEloquentで十分。

### 4.2 クエリビルダが必要な場合

```php
$topHubs = DB::table('search_path_steps as s')
    ->join('search_history as h', 'h.id', '=', 's.history_id')
    ->join('pages as p', 'p.id', '=', 's.page_id')
    ->whereRaw('s.step_index > 0')
    ->whereRaw('s.step_index < h.clicks')
    ->select('p.title', DB::raw('COUNT(*) as appearances'))
    ->groupBy('p.id', 'p.title')
    ->orderByDesc('appearances')
    ->limit(20)
    ->get()
    ->all();
```

**Eloquentとクエリビルダの使い分け：**

| 場面 | 使うもの |
|---|---|
| 単一テーブルのCRUD | Eloquent |
| リレーション経由のデータ取得 | Eloquent + `with()` |
| 複数テーブルのJOIN + 集計 | クエリビルダ（`DB::table()`） |
| 生SQL が必要な複雑クエリ | `DB::raw()` や `whereRaw()` |

このクエリでは3テーブルをJOINして「スタートでもゴールでもない中継地点が何回登場したか」を集計している。Eloquentのリレーションだけでは表現しにくいため、クエリビルダを使っている。

#### `DB::raw()` の必要性

```php
DB::raw('COUNT(*) as appearances')
```

Laravelのselectメソッドはカラム名しか受け付けないので、`COUNT(*)` のようなSQL関数を使うには `DB::raw()` で「これはSQLとしてそのまま出力してくれ」と指定する必要がある。

---

## 5. `WikipediaPathFinder.php` — BFSエンジン

本アプリで最も複雑なクラス。双方向BFSアルゴリズムの実装。

### 5.1 コンストラクタ — 深さの正規化

```php
    public function __construct(string $lang, ?int $maxDepthPerSide = null)
    {
        $default = (int) config('finder.default_max_depth_per_side', 3);
        $min = (int) config('finder.min_depth_per_side', 1);
        $max = (int) config('finder.max_depth_per_side', 5);
        $depth = $maxDepthPerSide ?? $default;
        $this->maxDepthPerSide = max($min, min($max, $depth));
```

**クランプ（値の範囲制限）**: `max($min, min($max, $depth))` で、ユーザーが0や100を指定しても1〜5の範囲に収まる。`??` はnull合体演算子で、「左辺がnullなら右辺」。

### 5.2 依存性の内部構築

```php
        $this->api = new WikipediaApiClient($lang);
        $this->pageRepo = new PageRepository();
        $this->linkProvider = new LinkProvider(
            $lang, $this->api, $this->pageRepo, new LinkRepository()
        );
```

コメントに「本来はDI推奨だが、簡潔さ優先」とあるように、理想的にはコンストラクタ引数で受け取るべき。現在の設計でも動くが、テスト時にモック（偽物）を差し込みにくい。

### 5.3 `parseUrl()` — 静的ユーティリティ

```php
    public static function parseUrl(string $url): ?array
    {
        $url = trim($url);

        // 多重エンコードを安全にデコード（最大5回まで）
        for ($i = 0; $i < 5; $i++) {
            if (!preg_match('/%25[0-9A-Fa-f]{2}/', $url)) {
                break;
            }
            $url = rawurldecode($url);
        }

        if (!preg_match('~^https?://([a-z-]+)\.wikipedia\.org/wiki/([^?#\s]+)~u', $url, $m)) {
            return null;
        }
        $title = str_replace('_', ' ', rawurldecode($m[2]));
        return ['lang' => $m[1], 'title' => $title];
    }
```

**`static` にしている理由**: インスタンスの状態（言語設定等）に依存しない純粋関数だから。Controller側で `WikipediaPathFinder::parseUrl()` とインスタンス化なしで呼べる。

**多重エンコード対策**: `%25E3` のように `%` 自体がエンコードされている場合（一部ブラウザやコピー操作による多重エンコード）をループで最大5回デコードする。単発のif文ではなくループにすることで、3重・4重エンコードにも対応。

**`~` デリミタ**: 正規表現にURLの `/` が含まれるため、通常の `/` デリミタだとエスケープだらけになる。`~` を使うことで可読性を確保。

### 5.4 `findPath()` — 双方向BFSのメインループ

```php
    public function findPath(string $startTitle, string $goalTitle): array
    {
        // 1. タイトル正規化
        $start = $this->api->normalizeTitle($startTitle);
        if ($start === null) {
            return ['error' => "スタートページ「{$startTitle}」が見つかりません。"];
        }

        // 2. 同一ページチェック
        if ($start === $goal) {
            $idMap = $this->pageRepo->ensurePages([$start]);
            return ['path' => [$start], 'path_ids' => [$idMap[$start]], 'clicks' => 0];
        }

        // 3. BFS初期化
        $fwdParents = [$start => null];   // forward側の訪問済み
        $bwdChildren = [$goal => null];   // backward側の訪問済み
        $fwdFrontier = [$start];
        $bwdFrontier = [$goal];
```

#### データ構造の意味

```
fwdParents[X] = Y   ⇔ YからXへリンクがある（forward方向で Y→X と辿った）
bwdChildren[X] = Y  ⇔ XからYへリンクがある（backward方向で Y←X と辿った）
```

`bwdChildren` は backward方向に探索しているが、**保存している関係はforward方向**。これにより `buildResult()` で経路を組み立てるときに方向が一致する。

### 5.5 `chooseSide()` — 展開方向の選択

```php
    private function chooseSide(...): ?string
    {
        $fwdExhausted = empty($fwdFrontier) || $fwdDepth >= $this->maxDepthPerSide;
        $bwdExhausted = empty($bwdFrontier) || $bwdDepth >= $this->maxDepthPerSide;

        if ($fwdExhausted && $bwdExhausted) return null;
        if ($fwdExhausted) return 'backward';
        if ($bwdExhausted) return 'forward';

        return count($fwdFrontier) <= count($bwdFrontier) ? 'forward' : 'backward';
    }
```

**「小さい方のフロンティアを優先」する理由**: フロンティアが偏ったとき、大きい方を展開するとAPI呼び出しが膨らむ。常に小さい方を展開することで、最悪計算量を $O(b^{d/2})$ に保てる。

### 5.6 `buildResult()` — 経路復元

```php
        // 出会いノードからstartまで遡る
        $forward = [];
        $cur = $meetingNode;
        while ($cur !== null) {
            array_unshift($forward, $cur);  // 先頭に追加（逆順を直す）
            $cur = $fwdParents[$cur] ?? null;
        }
        // 出会いノードからgoalまで辿る
        $backward = [];
        $cur = $bwdChildren[$meetingNode] ?? null;
        while ($cur !== null) {
            $backward[] = $cur;  // 末尾に追加（順方向）
            $cur = $bwdChildren[$cur] ?? null;
        }
        $path = array_merge($forward, $backward);
```

```
start → A → B → meetingNode → C → D → goal
                    ↑
              ここで出会った
```

- `fwdParents` を辿ると `meetingNode → B → A → start`（逆順）→ `array_unshift` で正順に
- `bwdChildren` を辿ると `C → D → goal`（順方向）→ そのまま末尾追加
- meetingNodeは `$forward` 側に含まれるので `$backward` からは外す（`$bwdChildren[$meetingNode]` から開始）

---

## 6. `WikipediaApiClient.php` — API通信層

### 6.1 リトライ・スロットリングロジック

```php
    private function get(array $params): ?Response
    {
        $maxAttempts = 4;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // ★ スロットリング: 前回リクエストから最低間隔を空ける
                $this->throttle();

                $this->apiCalls++;
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout($this->timeout)
                    ->get($this->apiUrl, $params);

                if ($response->ok()) {
                    $this->easeThrottle();  // 成功時はスロットルを緩和
                    return $response;
                }

                $status = $response->status();
                $isRetryable = $status === 429 || $response->serverError();

                if ($isRetryable && $attempt < $maxAttempts) {
                    if ($status === 429) {
                        $this->raiseThrottle();  // 429時はスロットルを引き上げ
                    }
                    $backoffMs = $this->resolveBackoffMs($response, $attempt, $status);
                    usleep($backoffMs * 1000);
                    continue;
                }
                return $response;
            } catch (\Throwable $e) { /* リトライ */ }
        }
        return null;
    }
```

**旧版との大きな違い**: リトライ回数が2→4に増え、アダプティブスロットリングが追加された。

**アダプティブスロットリングの3つのメソッド**:
- `throttle()` — 前回リクエストからの経過時間が最低間隔未満ならスリープ
- `raiseThrottle()` — 429受信時にスロットル間隔を2倍に引き上げ（最大5秒）
- `easeThrottle()` — 成功時にスロットルを10%ずつ緩和（急に下げると再び429になるため）

**`resolveBackoffMs()`** — Retry-Afterヘッダがあればその秒数を優先し、なければ指数バックオフにフォールバック。429は5s→10s→20s、5xxは500ms→1s→2sと重みを変えている。

**`Http` ファサード**: LaravelのHTTPクライアント。内部的にはGuzzleHTTPを使っているが、Laravel流の簡潔なAPIで呼べる。

### 6.2 バッチAPI取得

```php
    public function getBatchOutgoingLinks(array $titles): array { ... }
    public function getBatchIncomingLinks(array $titles): array { ... }
```

複数タイトルをパイプ区切り（`implode('|', $chunk)`）でまとめて1リクエストで取得する。1タイトルずつAPIを叩くよりもリクエスト数を大幅に削減できる。outgoingは10件ずつ、incomingは10件ずつチャンク処理する（incomingは1ページあたりのリンク数が多く、`lhcontinue`が膨れるため小さめのバッチサイズ）。

### 6.3 ページネーション対応（`plcontinue`）

```php
    public function getAllOutgoingLinks(string $title): array
    {
        $allLinks = [];
        $plcontinue = null;
        do {
            $params = [ /* ... */ ];
            if ($plcontinue !== null) {
                $params['plcontinue'] = $plcontinue;
            }
            $response = $this->get($params);
            // ... リンクを $allLinks に追加 ...
            $plcontinue = $data['continue']['plcontinue'] ?? null;
        } while ($plcontinue !== null);
        return $allLinks;
    }
```

Wikipedia APIは1リクエストで最大500件のリンクしか返さない。`plcontinue` パラメータを使って「続き」を取得するページネーション。**巨大な記事（「日本」など）は1000件以上のリンクを持つため、この処理は必須。**

---

## 7. `LinkProvider.php` — キャッシュ層

### 7.1 鮮度判定と振り分け

```php
    private function getLinks(array $titles, string $direction): array
    {
        // 1. ページIDを確保
        $idMap = $this->pageRepo->ensurePages($titles);

        // 2. 鮮度判定（方向によって使う判定メソッドが異なる）
        $freshnessMap = $direction === 'outgoing'
            ? $this->pageRepo->getFreshnessMap($pageIds)
            : $this->pageRepo->getIncomingFreshnessMap($pageIds);

        // 3. 分類（fresh / check / missing）
        $classified = $this->classifyByFreshness($idMap, $freshnessMap);

        // 4. checkグループの処理（outgoingのみtouched比較）
        if (!empty($classified['check'])) {
            if ($direction === 'outgoing') {
                $needFetch = $this->verifyAndRedistribute($classified);
            } else {
                // incoming: touchedでは判定できないので一律再取得
                $needFetch = $classified['check'];
            }
        }

        // 5. needFetch + missing をAPIから取得
        if (!empty($needFetch)) {
            if ($direction === 'outgoing') {
                $this->fetchAndStoreOutgoing($needFetch);
            } else {
                $this->fetchAndStoreIncoming($needFetch);
            }
        }

        // 6. DBから一括でリンクを取得（方向によって異なるメソッド）
        if ($direction === 'outgoing') {
            $linkIdsByPageId = $this->linkRepo->getOutgoingTargetIds($allIds);
        } else {
            $linkIdsByPageId = $this->linkRepo->getIncomingSourceIds($allIds);
        }
        // ...
    }
```

**旧版との大きな違い**: 第2引数が `bool $outgoing` から `string $direction` に変更され、incoming方向のリンクもキャッシュされるようになった。

**この層が存在する理由**: PathFinderは「リンクが欲しい」とだけ言う。キャッシュがあるかDBから読むかAPIから取るかは、このLinkProviderが判断する。**PathFinderはデータの取得元を知らない。**

**Incoming保存の特殊性**: Outgoingは `replaceOutgoingLinks()`（全置換）で保存するが、Incomingは `addIncomingLinks()`（`insertOrIgnore`による追加のみ）で保存する。これはforward探索が先に保存した `(source, target)` レコードを壊さないため。

### 7.2 空応答ガード

```php
        if (empty($linkTitles)) {
            \Log::warning('[LinkProvider] empty result from API, skipping save', ...);
            continue;
        }
```

APIが一時的に0件を返した場合、**DBの既存データを上書きしない**。これにより、Wikipedia APIの一時的な障害で過去の正しいキャッシュが破壊されることを防ぐ。

---

## 8. `app/Repositories/` — DB操作の抽象化

### 8.1 `PageRepository::ensurePages()` — Upsertパターン

```php
    public function ensurePages(array $titles): array
    {
        $titles = array_values(array_unique($titles));

        // 既存を一括取得（10,000件ずつチャンク処理）
        $existing = [];
        foreach (array_chunk($titles, 10000) as $chunk) {
            $existing += Page::whereIn('title', $chunk)->pluck('id', 'title')->all();
        }

        // 不足分を作成
        $missing = array_diff($titles, array_keys($existing));
        if (!empty($missing)) {
            foreach (array_chunk($missing, 10000) as $chunk) {
                $rows = array_map(fn ($t) => ['title' => $t], $chunk);
                Page::insertOrIgnore($rows);
                $reread = Page::whereIn('title', $chunk)->pluck('id', 'title')->all();
                $existing += $reread;
            }
        }
        return $existing;
    }
```

**`insertOrIgnore`**: 既にあってもエラーにならない。BFS探索では同じタイトルが何度もensurePagesに渡されるため、レース条件（同時アクセスで重複挿入される）への対策として重要。

**`pluck('id', 'title')`**: `['日本' => 1, '東京' => 2]` のような「値 => キー」の連想配列を返すEloquentメソッド。

**チャンク処理**: SQLiteの `WHERE IN` にはプレースホルダ上限があるため、10,000件ずつに分割して処理する。

### 8.2 `LinkRepository` — Outgoing/Incoming両対応

```php
    // Outgoing: 全置換（古いリンクを削除して新しいリンクを挿入）
    public function replaceOutgoingLinks(int $sourceId, array $targetIds): void { ... }

    // Incoming: 追加のみ（既存は削除しない）
    public function addIncomingLinks(int $targetId, array $sourceIds): void { ... }

    // 読み取り（両方向）
    public function getOutgoingTargetIds(array $sourceIds): array { ... }
    public function getIncomingSourceIds(array $targetIds): array { ... }
```

**Outgoing vs Incoming の保存戦略の違い**:
- Outgoing: `replaceOutgoingLinks()` — 1ページのリンクを全削除→全挿入。正確性を重視（リンクが削除された場合も反映）
- Incoming: `addIncomingLinks()` — `insertOrIgnore` で追加のみ。forward探索が先に保存した `(source, target)` レコードを壊さないため

### 8.3 `SearchHistoryRepository::record()` — トランザクション

```php
    public function record(...): int
    {
        return DB::transaction(function () use (...) {
            $history = SearchHistory::create([...]);

            if ($found && !empty($pathPageIds)) {
                $rows = [];
                foreach ($pathPageIds as $i => $pid) {
                    $rows[] = ['history_id' => $history->id, 'step_index' => $i, 'page_id' => $pid];
                }
                SearchPathStep::insert($rows);
            }
            return $history->id;
        });
    }
```

**`DB::transaction()`**: コールバック内の全SQL操作がアトミック（全成功 or 全ロールバック）になる。`search_history` に行を挿入したが `search_path_steps` の挿入に失敗した場合、`search_history` の挿入もなかったことになる。**データの整合性を保証する基本パターン。**

---

## 9. `app/Models/` — Eloquentモデル群

### 9.1 `SearchHistory.php` の `$casts`

```php
    protected $casts = [
        'found'              => 'boolean',
        'searched_at'        => 'datetime',
        'clicks'             => 'integer',
        'duration_ms'        => 'integer',
    ];
```

DBから取得した値をPHPの型に自動変換する仕組み：
- `'boolean'` — DB上の0/1をPHPのtrue/falseに変換
- `'datetime'` — 文字列をCarbonオブジェクトに変換（`$h->searched_at->format('Y-m-d')` のように使える）
- `'integer'` — 文字列を整数に変換

**`$casts` を書かないとどうなるか**: DBドライバによっては全て文字列で返ってくるため、`$h->found` が `"1"` になり、`if ($h->found)` は動くが `$h->found === true` は `false` になるという罠にハマる。

### 9.2 `Link.php` の複合主キー対応

```php
    public $incrementing = false;
    protected $primaryKey = null;
```

Eloquentは本来、単一のAUTO_INCREMENT主キーを前提としている。複合主キー `(source_id, target_id)` を使う場合、`find()` や `save()` は使えないが、**`where()` + `insert()` + `delete()` は問題なく動く**。本アプリではRepositoryを経由してこれらのメソッドだけを使っている。

---

## 10. `database/migrations/` — テーブル定義

### 10.1 linksテーブルの複合主キーと外部キー

```php
Schema::create('links', function (Blueprint $table) {
    $table->unsignedInteger('source_id');
    $table->unsignedInteger('target_id');
    $table->primary(['source_id', 'target_id']);      // 複合主キー
    $table->index('target_id', 'idx_target');          // 逆引きインデックス
    $table->foreign('source_id')->references('id')->on('pages')->cascadeOnDelete();
    $table->foreign('target_id')->references('id')->on('pages')->cascadeOnDelete();
});
```

- **複合主キー**: `(source_id, target_id)` の組み合わせでユニーク。同じリンクの重複挿入を防ぐ
- **`idx_target`**: 後方探索で「このページにリンクしているページ」を高速に取得するためのインデックス
- **`cascadeOnDelete()`**: 親（pagesテーブル）の行が削除されたら、対応するリンクも自動削除

### 10.2 pagesテーブルの照合順序

```php
\DB::statement('ALTER TABLE pages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
```

Laravelのマイグレーションでは照合順序を直接指定できないため、生SQLを使っている。`utf8mb4_bin` は**大文字・小文字を区別する**照合順序。Wikipediaでは「日本」と「日本語」は別記事なので、大文字小文字の区別が必要。

### 10.3 page_metaへのincomingキャッシュカラム追加

```php
// 2026_05_03_023275_add_incoming_cache_to_page_meta.php
Schema::table('page_meta', function (Blueprint $table) {
    $table->dateTime('incoming_fetched_at')->nullable()->after('link_count');
    $table->unsignedInteger('incoming_link_count')->default(0)->after('incoming_fetched_at');
    $table->index('incoming_fetched_at', 'idx_incoming_fetched');
});
```

後方探索（incoming）のリンクもDBにキャッシュするようになったため、`page_meta` テーブルに2カラムを追加するマイグレーション。既存のテーブルにカラムを追加する `Schema::table()` の好例。`nullable()` は既存行にnullが入ることを許可する（まだincomingリンクを取得していないページ用）。

---

## 11. `resources/views/` — Bladeテンプレート

### 11.1 レイアウト継承（`layouts/app.blade.php`）

```blade
<nav class="navbar">
    <a href="{{ route('finder.index') }}" class="brand">🔗 Wikipedia 6クリック挑戦</a>
    <a href="{{ route('finder.index') }}"
       class="{{ request()->routeIs('finder.*') ? 'active' : '' }}">探索</a>
    <a href="{{ route('history.index') }}"
       class="{{ request()->routeIs('history.*') ? 'active' : '' }}">履歴</a>
    <a href="{{ route('stats.index') }}"
       class="{{ request()->routeIs('stats.*') ? 'active' : '' }}">統計</a>
</nav>
```

**`request()->routeIs('finder.*')`**: 現在のルート名が `finder.` で始まるかを判定。`*` はワイルドカード。ナビゲーションの「現在地ハイライト」に使う常套手段。

### 11.2 `finder.blade.php` — JSへの設定注入

```blade
@push('scripts')
<script>
    window.FINDER_CONFIG = {
        suggestUrl: '{{ route("finder.suggest") }}',
        streamUrl: '{{ route("finder.stream") }}',
    };
</script>
<script src="{{ asset('js/autocomplete.js') }}"></script>
<script src="{{ asset('js/finder.js') }}"></script>
@endpush
```

**サーバーサイドレンダリング時にBladeが評価される**ので、HTMLがブラウザに届くときには既に：

```html
<script>
    window.FINDER_CONFIG = {
        suggestUrl: 'http://localhost:8000/suggest',
        streamUrl: 'http://localhost:8000/find-path/stream',
    };
</script>
```

のように文字列リテラルに展開されている。外部JSファイルからは `window.FINDER_CONFIG.suggestUrl` で参照。**URLのハードコードを完全に排除できる。**

### 11.3 `history/index.blade.php` — ページネーション

```blade
<div class="pagination-wrap">
    {!! $histories->links() !!}
</div>
```

`{!! !!}` はエスケープなしのHTML出力。`$histories->links()` はLaravelが自動生成するページネーションHTML（「前へ」「1」「2」「3」「次へ」のリンク群）。`{{ }}` だとHTMLタグがエスケープされて表示されてしまうので `{!! !!}` を使う。

---

## 12. `public/js/` — フロントエンドJS

### 12.1 `Autocomplete` クラスの状態遷移

```
[キーワード入力モード]
   <input> が表示、<chip-display> は display:none
   ↓ 候補クリック / URL貼付
   ↓ setChip(title, url)
[チップ表示モード]
   <chip-display> が表示、<input> は display:none
   hidden inputに実URLが入っている
   ↓ ×ボタンクリック
   ↓ clearChip()
[キーワード入力モードに戻る]
```

**表示用 `<input>` と送信用 `<input type="hidden">` を分離している理由**：
- ユーザーは「タイトル」を見たい（URLは長くて視認性が悪い）
- サーバーは「URL」を欲しい（言語とタイトルを抽出するため）
- 両方を1つの `<input>` で兼ねると、どちらかのUXが犠牲になる

### 12.2 `finder.js` の経過時間タイマー

```javascript
const tick = () => {
    if (timerStopped) return;
    const elapsedMs = performance.now() - startedAt;
    const sinceLastMs = performance.now() - lastEventAt;
    timerEl.textContent = (elapsedMs / 1000).toFixed(1) + 's';
    timerEl.classList.toggle('stalled', sinceLastMs > 5000);
    timerRafId = requestAnimationFrame(tick);
};
```

**`requestAnimationFrame` を `setInterval` の代わりに使う理由**：
- バックグラウンドタブで自動的に停止（CPU節約）
- ブラウザの描画タイミングと同期（滑らかな表示更新）
- フレーム落ちが起きにくい

**`sinceLastMs > 5000`**: 最後のSSEイベントから5秒以上経過したら、タイマーを赤色にして「応答待ち」を示す。ユーザーは「フリーズ」と「正常に時間がかかっている」を区別できる。

---

## 13. `config/finder.php` — 独自設定ファイル

```php
return [
    'default_max_depth_per_side' => env('FINDER_DEFAULT_DEPTH', 3),
    'min_depth_per_side' => 1,
    'max_depth_per_side' => 5,
    'fresh_ttl_hours'   => env('FINDER_FRESH_TTL_HOURS', 24),
    'max_ttl_days'       => env('FINDER_MAX_TTL_DAYS', 7),
    'pool_size'          => 20,
    'timeout'            => 15,
];
```

**`env()` を使う値と使わない値の違い**：
- `env()` あり → 環境（開発/本番）によって変えたい値。`.env` ファイルで上書き可能
- `env()` なし → どの環境でも固定の値。コードを変更しない限り変わらない

**設定ファイルを独自に作れるのは `config/` ディレクトリの強み**。`config/` に置いたPHPファイルは、ファイル名がそのまま設定キーのプレフィックスになる（`finder.php` → `config('finder.xxx')`）。

---

## まとめ — 学習ポイントの対応表

| Laravel学習ポイント | 該当箇所 |
|---|---|
| ルーティングと名前付きルート | `routes/web.php` |
| コントローラとアクションメソッド | `PathFinderController` の3メソッド |
| Eloquent ORM とリレーション | `Models/*.php`、`with()` によるEager Loading |
| クエリビルダ（DB::table） | `StatsController` の集計クエリ |
| マイグレーション | `database/migrations/` の5ファイル |
| Bladeテンプレートと継承 | `layouts/app.blade.php` と子テンプレート |
| ページネーション | `HistoryController@index` |
| 設定ファイルと環境変数 | `config/finder.php`、`.env` |
| Service層とFat Controller回避 | `app/Services/` の3クラス |
| Repository層とトランザクション | `app/Repositories/` の3クラス |
| HTTPクライアント（Httpファサード） | `WikipediaApiClient` |
| ストリーミングレスポンス（SSE） | `PathFinderController@stream` |
| コールバック注入（IoC） | `setProgressCallback()` |
| ロギング（Logファサード） | 各所の `Log::error()` / `Log::warning()` |
| Carbonによる日時操作 | `PageRepository::getFreshnessMap()` |
