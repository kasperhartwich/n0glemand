<?php

namespace App\Console\Commands\Slack;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

#[Signature('slack:prune-ig-downloads')]
#[Description('Delete Instagram download directories left behind by jobs that were killed mid-run.')]
class PruneInstagramDownloads extends Command
{
    public function handle(): int
    {
        $root = storage_path('app/slack-ig');
        $cutoff = time() - (int) config('services.slack.ig_download_max_age', 60 * 60);
        $pruned = 0;

        foreach (File::isDirectory($root) ? File::directories($root) : [] as $dir) {
            if ((int) filemtime($dir) < $cutoff) {
                File::deleteDirectory($dir);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            Log::info('slack.ig.downloads_pruned', ['count' => $pruned]);
        }

        $this->info("Pruned {$pruned} download directories.");

        return self::SUCCESS;
    }
}
