<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Page extends Model
{
    protected $table = 'pages';
    public $timestamps = false;       // created_at / updated_at は使わない

    protected $fillable = ['title'];

    /** このページが「リンク元」になっているリンク群 */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'source_id');
    }

    /** このページが「リンク先」になっているリンク群 */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'target_id');
    }

    /** メタ情報 */
    public function meta(): HasOne
    {
        return $this->hasOne(PageMeta::class, 'page_id');
    }
}