<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ConsumeBackupRequest extends Command
{
    protected $signature = 'agent-center:consume-backup-request';

    protected $description = 'Consome somente a solicitação estruturada do administrador para o backup externo';

    public function handle(): int
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return self::FAILURE;
        }

        return Cache::pull('platform:backups:requested') === true ? self::SUCCESS : 2;
    }
}
