<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Wikipedia 6クリック挑戦')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('styles')
</head>
<body>
    <nav class="navbar">
        <a href="{{ route('finder.index') }}" class="brand">🔗 Wikipedia 6クリック挑戦</a>
        <a href="{{ route('finder.index') }}" class="{{ request()->routeIs('finder.*') ? 'active' : '' }}">探索</a>
        <a href="{{ route('history.index') }}" class="{{ request()->routeIs('history.*') ? 'active' : '' }}">履歴</a>
        <a href="{{ route('stats.index') }}" class="{{ request()->routeIs('stats.*') ? 'active' : '' }}">統計</a>
    </nav>

    <div class="container">
        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>