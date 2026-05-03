<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchHistory extends Model
{
    protected $table = 'search_history';
    public $timestamps = false;

    protected $fillable = [
        'start_id', 'goal_id', 'clicks', 'found',
        'duration_ms', 'api_calls', 'visited_count',
        'max_depth_per_side', 'searched_at',
    ];

    protected $casts = [
        'found'              => 'boolean',
        'searched_at'        => 'datetime',
        'clicks'             => 'integer',
        'duration_ms'        => 'integer',
        'api_calls'          => 'integer',
        'visited_count'      => 'integer',
        'max_depth_per_side' => 'integer',
    ];

    public function startPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'start_id');
    }

    public function goalPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'goal_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SearchPathStep::class, 'history_id')->orderBy('step_index');
    }
}