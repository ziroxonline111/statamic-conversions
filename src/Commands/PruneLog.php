<?php

namespace KareliaLabs\Conversions\Commands;

use Illuminate\Console\Command;
use KareliaLabs\Conversions\EventLog;
use Statamic\Console\RunsInPlease;

class PruneLog extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:conversions:prune';

    protected $description = 'Delete conversion log files older than the configured number of days';

    public function handle(EventLog $log): int
    {
        $this->info(trans_choice('{0} Nothing to delete.|{1} Deleted 1 log file.|[2,*] Deleted :count log files.', $count = $log->prune(), ['count' => $count]));

        return self::SUCCESS;
    }
}
