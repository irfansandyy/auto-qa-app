<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaRun extends Model
{
    use HasFactory;

    public const AUTOMATIC_GOAL = 'Check the starting page and a small sample of visible same-site links.';

    public const AUTOMATIC_EXPECTED_BEHAVIOR = 'The checked pages load and render without server errors.';

    public const ADVANCED_GOAL = 'Review the website at desktop and mobile widths, its public response configuration, and the approved repository where available.';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['automatic' => 'boolean', 'advanced' => 'boolean', 'patch_files' => 'array', 'verification' => 'array', 'report' => 'array',
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
}
