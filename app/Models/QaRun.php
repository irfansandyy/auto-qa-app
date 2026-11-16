<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class QaRun extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['patch_files' => 'array', 'verification' => 'array', 'report' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class, 'run_id')->orderBy('sequence');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'run_id');
    }

    public function chatSession(): HasOne
    {
        return $this->hasOne(ChatSession::class);
    }

    public function toolExecutionLogs(): MorphMany
    {
        return $this->morphMany(ToolExecutionLog::class, 'loggable');
    }
}
