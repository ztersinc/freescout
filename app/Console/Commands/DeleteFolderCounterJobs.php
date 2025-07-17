<?php

namespace App\Console\Commands;

use App\Job;
use Illuminate\Console\Command;

class DeleteFolderCounterJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:delete-folder-counter-jobs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete the jobs that may have accumulated so far for folder counters';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Job::where('payload', 'like', '%UpdateFolderCounters%')->delete();
        $this->info('Delete completed folder counter jobs');
    }
}