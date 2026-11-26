<?php

namespace App\Console\Commands;

use App\Services\OriginAutoEvacuateService;
use Illuminate\Console\Command;

class AutoEvacuateDeadOrigins extends Command
{
    protected $signature = 'origin:auto-evacuate';

    protected $description = 'Якщо origin down 10+ хв — три SSH-спроби раз на хвилину, потім евакуація оферів';

    public function handle(OriginAutoEvacuateService $autoEvacuate): int
    {
        $stats = $autoEvacuate->tick();
        $this->info(
            'skipped='.$stats['skipped']
            .' waiting='.$stats['waiting']
            .' confirming='.$stats['confirming']
            .' recovered='.$stats['recovered']
            .' evacuated='.$stats['evacuated']
            .' blocked='.$stats['blocked']
        );

        return self::SUCCESS;
    }
}
