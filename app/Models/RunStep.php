<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(QaRun::class, 'run_id');
    }
}
