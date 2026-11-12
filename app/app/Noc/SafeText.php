<?php

namespace App\Noc;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (preg_match('/(?:password|senha|secret|token|community|authorization|api[_ -]?key)\s*[:=]\s*\S|-----BEGIN .*PRIVATE KEY|\b(?:sk-[A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[A-Z0-9]{16}|[0-9]{5,20}:[A-Za-z0-9_-]{30,})/i', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value))) {
            $fail('Não armazene credenciais, tokens ou conteúdo de controle no inventário.');
        }
    }
}
