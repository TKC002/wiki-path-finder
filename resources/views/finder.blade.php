@extends('layouts.app')

@section('title', 'Wikipedia 6クリック挑戦')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/finder.css') }}">
@endpush

@section('content')
<div class="card">
    <h1>🔗 Wikipedia 6クリック挑戦</h1>
    <p class="subtitle">
        Wikipediaのどのページからどのページへも6クリック以内で移動できる、という噂を確かめよう。
    </p>

    <form id="pathForm">
        <div class="form-group">
            <label for="start_input">🚀 スタートページ</label>
            <div class="suggest-wrap" id="start_wrap">
                <input type="text" id="start_input" class="suggest-input" autocomplete="off"
                    placeholder="キーワード(例: 日本)または https://ja.wikipedia.org/wiki/日本">
                <div class="chip-display" id="start_chip"></div>
                <input type="hidden" id="start_url" name="start_url">
                <div class="suggest-list" id="start_suggest" hidden></div>
            </div>
        </div>

        {{-- ★ 入れ替えボタン --}}
        <div class="swap-row">
            <button type="button" id="swapBtn" class="swap-btn" title="スタートとゴールを入れ替え">⇅</button>
        </div>
        
        <div class="form-group">
            <label for="goal_input">🎯 ゴールページ</label>
            <div class="suggest-wrap" id="goal_wrap">
                <input type="text" id="goal_input" class="suggest-input" autocomplete="off"
                    placeholder="キーワード(例: アーネスト・ヘミングウェイ)または完全なURL">
                <div class="chip-display" id="goal_chip"></div>
                <input type="hidden" id="goal_url" name="goal_url">
                <div class="suggest-list" id="goal_suggest" hidden></div>
            </div>
        </div>
        <div class="depth-control">
            <div class="depth-header">
                <span>🔍 探索の深さ(片側)</span>
                <span class="depth-value">
                    <span id="depthValue">3</span> (最大 <span id="depthMax">6</span> クリック)
                </span>
            </div>
            <input type="range" id="depthSlider" class="depth-slider" min="1" max="5" step="1" value="3" name="depth">
            <div class="depth-help">
                値を大きくすると<b>より遠いペアでも経路が見つかる</b>ようになりますが、<b>探索時間が指数的に増えます</b>。
            </div>
        </div>
        <button type="submit" id="submitBtn">最短経路を探す</button>
    </form>

    <div class="hint">
        ⚠️ Wikipedia APIへの多数のリクエストが必要なため、組み合わせによっては数十秒〜数分かかります。
        無関係なページ同士ほど時間がかかります。
    </div>

    <div id="result"></div>
</div>
@endsection

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