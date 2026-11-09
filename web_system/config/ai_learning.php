<?php

return [
    // Export/feedback capture is automatic; CPU training requires explicit setup.
    // This switch NEVER authorizes automatic activation of a candidate model.
    'enabled' => (bool) env('AI_LEARNING_ENABLED', false),
    'python' => env('AI_LEARNING_PYTHON', ''),
    'project_directory' => env('AI_LEARNING_PROJECT_DIRECTORY', base_path('../ai_service')),
    'base_model' => env('AI_LEARNING_BASE_MODEL', ''),
    'holdout' => env('AI_LEARNING_HOLDOUT', ''),
    'min_new' => max(1, (int) env('AI_LEARNING_MIN_NEW', 50)),
    'target_accuracy' => (float) env('AI_LEARNING_TARGET_ACCURACY', 0.8),
    'critical_recall' => (float) env('AI_LEARNING_CRITICAL_RECALL', 0.95),
    'min_cell' => max(1, (int) env('AI_LEARNING_MIN_CELL', 5)),
    'timeout' => max(60, (int) env('AI_LEARNING_TIMEOUT', 1800)),
];
