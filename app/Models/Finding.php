<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Finding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(QaRun::class, 'run_id');
    }
}
