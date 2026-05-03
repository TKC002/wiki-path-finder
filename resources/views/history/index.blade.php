@extends('layouts.app')

@section('title', '探索履歴 - Wikipedia 6クリック挑戦')

@section('content')
<div class="card">
    <h1>📜 探索履歴</h1>
    <p class="subtitle">過去に行われた探索の記録です。新しい順に表示しています。</p>

    <div class="filter-bar">
        <a href="{{ route('history.index') }}" class="{{ $filter === 'all' ? 'active' : '' }}">すべて</a>
        <a href="{{ route('history.index', ['filter' => 'found']) }}" class="{{ $filter === 'found' ? 'active' : '' }}">成功のみ</a>
        <a href="{{ route('history.index', ['filter' => 'failed']) }}" class="{{ $filter === 'failed' ? 'active' : '' }}">失敗のみ</a>
    </div>

    @if ($histories->isEmpty())
        <div class="empty">まだ履歴がありません。</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>日時</th>
                    <th>スタート</th>
                    <th>ゴール</th>
                    <th class="num">クリック</th>
                    <th class="num">時間</th>
                    <th class="num">深さ</th>
                    <th class="num">API</th>
                    <th>結果</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($histories as $h)
                    <tr>
                        <td>{{ $h->searched_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $h->startPage->title ?? '不明' }}</td>
                        <td>{{ $h->goalPage->title ?? '不明' }}</td>
                        <td class="num">{{ $h->clicks ?? '-' }}</td>
                        <td class="num">{{ number_format($h->duration_ms / 1000, 2) }}s</td>
                        <td class="num">{{ $h->max_depth_per_side }}</td>
                        <td class="num">{{ number_format($h->api_calls) }}</td>
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

        <div class="pagination-wrap">
            {!! $histories->links() !!}
        </div>
    @endif
</div>
@endsection