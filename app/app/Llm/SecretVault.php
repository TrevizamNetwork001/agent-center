<?php

namespace App\Llm;

use Illuminate\Encryption\Encrypter;

class SecretVault
{
    private function key(string $path): ?string
    {
        if (! $path || ! is_file($path) || is_link($path) || (fileperms($path) & 0077) || filesize($path) > 128) {
            return null;
        }$k = base64_decode(trim(file_get_contents($path)), true);

        return is_string($k) && strlen($k) === 32 ? $k : null;
    }

    public function encrypt(#[\SensitiveParameter] string $secret, string $provider, int $tenant): array
    {
        $k = $this->key((string) config('llm.key_file'));
        if (! $k) {
            throw new \RuntimeException('LLM master key unavailable');
        }

        return ['secret_ciphertext' => (new Encrypter($k, 'aes-256-gcm'))->encryptString(json_encode(['provider' => $provider, 'tenant' => $tenant, 'secret' => $secret], JSON_THROW_ON_ERROR)), 'secret_key_id' => substr(hash('sha256', $k), 0, 16)];
    }

    public function decrypt($provider): ?string
    {
        if (! $provider->secret_ciphertext) {
            return null;
        }foreach ([config('llm.key_file'), ...config('llm.previous_key_files', [])] as $path) {
            $k = $this->key((string) $path);
            if (! $k || ! hash_equals(substr(hash('sha256', $k), 0, 16), $provider->secret_key_id ?? '')) {
                continue;
            }try {
                $v = json_decode((new Encrypter($k, 'aes-256-gcm'))->decryptString($provider->secret_ciphertext), true, 8, JSON_THROW_ON_ERROR);
                if (($v['provider'] ?? '') === $provider->id && ($v['tenant'] ?? 0) === $provider->tenant_id && is_string($v['secret'] ?? null)) {
                    return $v['secret'];
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
