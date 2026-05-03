<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchPathStep extends Model
{
    protected $table = 'search_path_steps';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = ['history_id', 'step_index', 'page_id'];

    protected $casts = [
        'step_index' => 'integer',
    ];

    public function history(): BelongsTo
    {
        return $this->belongsTo(SearchHistory::class, 'history_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}