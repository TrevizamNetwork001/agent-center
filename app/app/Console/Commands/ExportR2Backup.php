<?php

namespace App\Console\Commands;

use App\Backups\R2Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportR2Backup extends Command
{
    protected $signature = 'agent-center:r2-export {--clear}';

    protected $description = 'Exporta credenciais R2 somente para diretório temporário privado do operador';

    public function handle(R2Settings $settings): int
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->error('Exportação restrita ao processo administrativo de backup.');

            return self::FAILURE;
        }
        $directory = '/run/agent-center-r2-export';
        try {
            if ($this->option('clear')) {
                File::deleteDirectory($directory);

                return self::SUCCESS;
            }
            $data = $settings->read();
            if (! $settings->ready($data)) {
                $this->info('Configuração R2 incompleta.');

                return 2;
            }
            File::ensureDirectoryExists($directory, 0700);
            File::replace($directory.'/r2-credentials.json', json_encode(['access_key_id' => $data['access_key_id'], 'secret_access_key' => $data['secret_access_key']], JSON_THROW_ON_ERROR), 0600);
            File::replace($directory.'/backup-recipient.txt', $data['recipient']."\n", 0600);
            $config = ['account_id' => $data['account_id'], 'endpoint' => 'https://'.$data['account_id'].'.r2.cloudflarestorage.com', 'bucket' => 'agent-center', 'prefix' => 'agent-center', 'region' => 'auto'];
            File::replace($directory.'/r2-backup.json', json_encode($config, JSON_THROW_ON_ERROR), 0600);
            $this->info('Configuração exportada para diretório privado temporário.');

            return self::SUCCESS;
        } catch (\Throwable) {
            File::deleteDirectory($directory);
            $this->error('Exportação R2 não concluída; detalhes sensíveis omitidos.');

            return self::FAILURE;
        }
    }
}
