<?php

namespace App\Backups;

use App\Models\User;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class R2Settings
{
    public function path(): string
    {
        return storage_path('app/platform-backups/r2.enc');
    }

    public function read(): array
    {
        if (! File::exists($this->path())) {
            return [];
        }

        return json_decode(Crypt::decryptString(File::get($this->path())), true, flags: JSON_THROW_ON_ERROR);
    }

    public function summary(): array
    {
        $data = $this->read();

        return [
            'account' => ! empty($data['account_id']),
            'credentials' => ! empty($data['access_key_id']) && ! empty($data['secret_access_key']),
            'recipient' => ! empty($data['recipient']),
            'custody_attested' => ! empty($data['offsite_attested_at']),
            'ready' => $this->ready($data),
            'saved_at' => $data['saved_at'] ?? null,
        ];
    }

    public function ready(array $data): bool
    {
        return ! empty($data['account_id']) && ! empty($data['access_key_id']) && ! empty($data['secret_access_key']) && ! empty($data['recipient']) && ! empty($data['offsite_attested_at']);
    }

    public function save(array $input, User $actor): void
    {
        Cache::lock('platform:backups:settings', 10)->block(3, function () use ($input, $actor): void {
            $before = File::exists($this->path()) ? File::get($this->path()) : null;
            $data = $this->read();
            foreach (['account_id', 'access_key_id', 'secret_access_key', 'recipient'] as $key) {
                if (! empty($input['r2_'.$key])) {
                    $data[$key] = $input['r2_'.$key];
                }
            }
            foreach (['account_id', 'access_key_id', 'secret_access_key'] as $required) {
                if (empty($data[$required])) {
                    throw ValidationException::withMessages(['r2_'.$required => 'Preencha este campo para a configuração inicial.']);
                }
            }
            if (! empty($input['r2_recipient']) && empty($input['r2_offsite_ack'])) {
                throw ValidationException::withMessages(['r2_offsite_ack' => 'Confirme a custódia da chave privada fora desta VPS.']);
            }
            if (! empty($input['r2_offsite_ack'])) {
                $data['offsite_attested_at'] = now()->toIso8601String();
            }
            $data['saved_at'] = now()->toIso8601String();
            $data['bucket'] = 'agent-center';
            $data['prefix'] = 'agent-center';
            $data['region'] = 'auto';
            File::ensureDirectoryExists(dirname($this->path()), 0750);
            File::replace($this->path(), Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 0640);
            try {
                app(Audit::class)->record('backup.settings_updated', $actor, null, 'platform_backup');
            } catch (\Throwable $exception) {
                if ($before !== null) {
                    File::replace($this->path(), $before, 0640);
                } else {
                    File::delete($this->path());
                }
                throw $exception;
            }
        });
    }

    public function requestBackup(User $actor): void
    {
        if (! $this->ready($this->read())) {
            throw ValidationException::withMessages(['r2_recipient' => 'Complete as credenciais e a chave pública antes de solicitar o backup.']);
        }
        app(Audit::class)->record('backup.requested', $actor, null, 'platform_backup');
        Cache::put('platform:backups:requested', true, now()->addDay());
    }

    public function health(): array
    {
        $probe = json_decode(@file_get_contents(storage_path('framework/operations-probes.json')) ?: 'null', true);
        $out = ['local' => 'ATENÇÃO', 'external' => 'ATENÇÃO', 'message' => 'Aguardando observação atualizada do backup.'];
        if (! is_array($probe) || empty($probe['collected_at']) || CarbonImmutable::parse($probe['collected_at'])->lt(now()->subSeconds(90))) {
            return $out;
        }
        foreach ($probe['checks'] ?? [] as $check) {
            if (in_array($check['state'] ?? null, ['VERDE', 'ATENÇÃO', 'NO-GO'], true)) {
                if (($check['component'] ?? '') === 'backup_local') {
                    $out['local'] = $check['state'];
                } elseif (($check['component'] ?? '') === 'backup_external') {
                    $out['external'] = $check['state'];
                    $out['message'] = match ($check['reason'] ?? '') {
                        'r2_precheck_in_progress', 'r2_backup_in_progress' => 'Preparando e criptografando o backup.',
                        'r2_transfer_in_progress' => 'Enviando ao R2 e verificando a integridade. Aguarde a conclusão.',
                        'r2_encrypted_upload_download_verified' => 'Envio ao R2 concluído e integridade conferida por download. Restauração independente ainda pendente.',
                        'r2_ui_backup_failed', 'r2_backup_failed', 'r2_ui_bridge_unavailable' => 'Falha no backup externo. O envio não foi confirmado; é necessária verificação operacional.',
                        'EXTERNAL BACKUP NOT CONFIGURED' => 'O envio externo ainda não foi configurado ou verificado.',
                        'verified_backup_stale' => 'A cópia externa está atrasada. É necessária verificação operacional.',
                        default => 'Aguardando comprovação atualizada do backup externo.',
                    };
                }
            }
        }

        return $out;
    }
}
