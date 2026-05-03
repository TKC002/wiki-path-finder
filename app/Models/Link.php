<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Link extends Model
{
    protected $table = 'links';
    public $timestamps = false;
    public $incrementing = false;     // 主キーがAUTO_INCREMENTでない
    protected $primaryKey = null;     // 複合主キーなのでLaravel標準では扱えない

    protected $fillable = ['source_id', 'target_id'];

    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'source_id');
    }

    public function targetPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'target_id');
    }
}