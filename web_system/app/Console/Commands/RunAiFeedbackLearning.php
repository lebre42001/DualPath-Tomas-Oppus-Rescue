<?php

namespace App\Console\Commands;

use App\Services\AiTrainingFeedbackExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class RunAiFeedbackLearning extends Command
{
    protected $signature = 'ai-learning:run';

    protected $description = 'Export approved private feedback and optionally train an isolated candidate (never activate it)';

    public function handle(AiTrainingFeedbackExporter $exporter): int
    {
        if (! Schema::hasTable('ai_training_feedback')) {
            $this->error('AI feedback migration is required before running the learning workflow.');

            return self::FAILURE;
        }
        $export = $exporter->export();
        $this->info('Private feedback snapshot prepared: '.$export['counts']['eligible'].' eligible examples.');
        if (! config('ai_learning.enabled')) {
            $this->info('Candidate training is disabled. The active model was not changed.');

            return self::SUCCESS;
        }

        $python = (string) config('ai_learning.python');
        $directory = (string) config('ai_learning.project_directory');
        $baseModel = (string) config('ai_learning.base_model');
        if (! File::isFile($python) || ! File::isDirectory($directory) || ! File::isDirectory($baseModel)) {
            $this->error('Configure valid AI_LEARNING_PYTHON, PROJECT_DIRECTORY, and BASE_MODEL paths.');

            return self::FAILURE;
        }

        $command = [$python, '-m', 'src.ai_service.feedback_learning', '--feedback', $export['feedback_path'],
            '--state-dir', $exporter->directory().DIRECTORY_SEPARATOR.'candidate-state',
            '--base-model', $baseModel, '--min-new', (string) config('ai_learning.min_new'),
            '--target-accuracy', (string) config('ai_learning.target_accuracy'),
            '--critical-recall', (string) config('ai_learning.critical_recall'),
            '--min-cell', (string) config('ai_learning.min_cell'), '--train'];
        if ($holdout = config('ai_learning.holdout')) {
            $command[] = '--holdout';
            $command[] = (string) $holdout;
        }
        $process = new Process($command, $directory, [
            'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1', 'MKL_NUM_THREADS' => '1',
        ]);
        $process->setTimeout(config('ai_learning.timeout'));
        try {
            $process->run();
        } catch (\Throwable) {
            $this->error('Candidate workflow could not finish. Check its private state; no live model was changed.');

            return self::FAILURE;
        }
        // Do not echo subprocess output: a dependency may include input text in errors.
        if (! $process->isSuccessful()) {
            $this->error('Candidate workflow failed. Review the private candidate state; no live model was changed.');

            return self::FAILURE;
        }
        $this->info('Candidate workflow completed. Review its private evaluation report before any separate activation.');

        return self::SUCCESS;
    }
}
