@extends('layouts.app')

@section('title', '統計 - Wikipedia 6クリック挑戦')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/stats.css') }}">
@endpush

@section('content')
<div class="card">
    <h1>📊 統計</h1>

    <h2>全体</h2>
    <div class="stat-grid">
        <div class="stat-card">
            <span class="num">{{ number_format($totalSearches) }}</span>
            <span class="label">総探索数</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($foundSearches) }}</span>
            <span class="label">成功</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($failedSearches) }}</span>
            <span class="label">失敗</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($totalCachedPages) }}</span>
            <span class="label">キャッシュ済</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($totalPages) }}</span>
            <span class="label">登録ページ</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($totalLinks) }}</span>
            <span class="label">登録リンク</span>
        </div>
    </div>
</div>

@if (!empty($clicksDistribution))
<div class="card">
    <h2>クリック数の分布(成功した探索)</h2>
    @php
        $maxCnt = max(array_map(fn($d) => $d->cnt, $clicksDistribution));
    @endphp
    @foreach ($clicksDistribution as $d)
        <div class="bar-row">
            <span class="bar-label">{{ $d->clicks }} クリック</span>
            <div class="bar">
                <div class="bar-fill {{ $d->cnt === 0 ? 'zero' : '' }}"
                     style="width: {{ $maxCnt > 0 ? ($d->cnt / $maxCnt * 100) : 0 }}%;">
                    {{ $d->cnt }}
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif

@if (!empty($topHubs))
<div class="card">
    <h2>🌐 よく中継されるページ TOP20</h2>
    <p class="subtitle">スタートでもゴールでもなく、経路の途中に登場した回数が多いページ。Wikipediaのハブ的存在。</p>
    <table>
        <thead>
            <tr>
                <th class="num" style="width: 60px;">順位</th>
                <th>ページ</th>
                <th class="num">登場回数</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topHubs as $i => $h)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $h->title }}</td>
                    <td class="num">{{ $h->appearances }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if (!empty($topLinkedPages))
<div class="card">
    <h2>🔗 リンク数が多いページ TOP20(キャッシュ済の中で)</h2>
    <table>
        <thead>
            <tr>
                <th class="num" style="width: 60px;">順位</th>
                <th>ページ</th>
                <th class="num">リンク数</th>
                <th>取得日時</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topLinkedPages as $i => $p)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $p->title }}</td>
                    <td class="num">{{ number_format($p->link_count) }}</td>
                    <td>{{ \Carbon\Carbon::parse($p->fetched_at)->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if (!empty($slowest))
<div class="card">
    <h2>⏱ 最も時間がかかった探索 TOP10</h2>
    <table>
        <thead>
            <tr>
                <th>スタート</th>
                <th>ゴール</th>
                <th class="num">時間</th>
                <th class="num">クリック</th>
                <th>結果</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($slowest as $h)
                <tr>
                    <td>{{ $h->startPage->title ?? '不明' }}</td>
                    <td>{{ $h->goalPage->title ?? '不明' }}</td>
                    <td class="num">{{ number_format($h->duration_ms / 1000, 2) }}s</td>
                    <td class="num">{{ $h->clicks ?? '-' }}</td>
                    <td>
                        @if ($h->found)
                            <span class="badge badge-found">成功</span>
                        @else
                            <span class="badge badge-failed">失敗</span>
                        @endif
                    </td>
                    <td>
                        @if ($h->found)
                            <a href="{{ route('history.show', $h->id) }}">詳細</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if (!empty($popularPairs))
<div class="card">
    <h2>🔁 何度も検索されたペア</h2>
    <table>
        <thead>
            <tr>
                <th>スタート</th>
                <th>ゴール</th>
                <th class="num">回数</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($popularPairs as $p)
                <tr>
                    <td>{{ $p->startPage->title ?? '不明' }}</td>
                    <td>{{ $p->goalPage->title ?? '不明' }}</td>
                    <td class="num">{{ $p->cnt }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection