<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageMeta extends Model
{
    protected $table = 'page_meta';
    protected $primaryKey = 'page_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'page_id', 'wiki_touched_at', 'fetched_at', 'link_count',
    ];

    protected $casts = [
        'wiki_touched_at' => 'datetime',
        'fetched_at'      => 'datetime',
        'link_count'      => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}