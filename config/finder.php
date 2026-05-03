<?php

return [
    /*
     * 探索の深さ(片側)。デフォルト値。
     * フロントから値が指定された場合はそちらが優先される。
     */
    'default_max_depth_per_side' => env('FINDER_DEFAULT_DEPTH', 3),

    /*
     * 許容する深さの範囲。これを超える値はクランプされる。
     */
    'min_depth_per_side' => 1,
    'max_depth_per_side' => 5,

    /*
     * リンクキャッシュの鮮度判定(案C)
     */
    'fresh_ttl_hours'   => env('FINDER_FRESH_TTL_HOURS', 24),
    'max_ttl_days'      => env('FINDER_MAX_TTL_DAYS', 7),

    /*
     * Wikipedia APIへの並列リクエスト数
     */
    'pool_size'         => 20,

    /*
     * HTTPタイムアウト(秒)
     */
    'timeout'           => 15,
];