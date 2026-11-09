<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Private learning audit: never include this model in civilian/API projections. */
class AiTrainingFeedback extends Model
{
    protected $table = 'ai_training_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'original_ai_snapshot' => 'array',
            'approved_for_training' => 'boolean',
            'reviewed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
