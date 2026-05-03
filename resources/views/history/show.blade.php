@extends('layouts.app')

@section('title', "履歴 #{$history->id} - Wikipedia 6クリック挑戦")

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/stats.css') }}">
@endpush

@section('content')
<div class="card">
    <h1>📍 探索詳細 #{{ $history->id }}</h1>

    <div style="margin-bottom: 1.5rem;">
        <a href="{{ route('history.index') }}" style="font-size: 0.9rem;">← 履歴一覧に戻る</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="num">{{ $history->clicks ?? '-' }}</span>
            <span class="label">クリック</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($history->duration_ms / 1000, 2) }}</span>
            <span class="label">秒</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($history->visited_count) }}</span>
            <span class="label">訪問ノード</span>
        </div>
        <div class="stat-card">
            <span class="num">{{ number_format($history->api_calls) }}</span>
            <span class="label">APIコール</span>
        </div>
    </div>

    <p style="color: #718096; font-size: 0.9rem;">
        {{ $history->searched_at->format('Y年n月j日 H:i:s') }} に実行
        / 深さ設定: {{ $history->max_depth_per_side }}
        @if ($history->found)
            <span class="badge badge-found">成功</span>
        @else
            <span class="badge badge-failed">失敗</span>
        @endif
    </p>

    <h2>経路</h2>

    @if (empty($pathSteps))
        <div class="empty">経路情報がありません(失敗した探索)</div>
    @else
        <div class="path-container">
            @foreach ($pathSteps as $i => $step)
                @php
                    $isStart = $i === 0;
                    $isGoal = $i === count($pathSteps) - 1;
                    $cls = 'path-step' . ($isStart ? ' start' : ($isGoal ? ' goal' : ''));
                @endphp
                <div class="{{ $cls }}">
                    <a href="{{ $step['url'] }}" target="_blank" rel="noopener">{{ $step['title'] }}</a>
                </div>
                @if (!$isGoal)
                    <div class="path-arrow">↓</div>
                @endif
            @endforeach
        </div>
    @endif
</div>
@endsection