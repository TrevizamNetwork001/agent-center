<?php

namespace App\Conversation;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

// Bounded conversational memory only; never a target, confirmation or execution plan.
class ContextualTurn
{
    public function question(Conversation $c, string $body, int $sequence): void
    {
        if (! str_contains($body, '?')) {
            return;
        }
        preg_match_all('/[^.!?\n]*\?/u', $body, $questions);
        $prompt = trim(end($questions[0]) ?: $body);
        $s = Str::lower(Str::ascii($prompt));
        $kind = match (true) {
            (bool) preg_match('/quanto tempo|desde quando|quando (?:comecou|iniciou)|comecou hoje|comecou quando|acontecia antes/', $s) => preg_match('/espera|aguarda|resposta|retorno/', $s) ? 'waiting_duration' : 'onset',
            (bool) preg_match('/(?:todos|alguns|quantos).*(?:clientes|usuarios)|(?:clientes|usuarios).*(?:todos|alguns)/', $s) => 'affected',
            (bool) preg_match('/(?:recebeu|receber|houve|algum).*(?:retorno|resposta)/', $s) => 'reply_received',
            (bool) preg_match('/o que acontece|qual (?:e |eh )?(?:o )?sintoma|lentidao, quedas|lenta o tempo todo|vai e volta/', $s) => 'symptom',
            default => null,
        };
        $context = [...array_diff_key($c->context_json, ['pending_question' => true]), 'context_memory_version' => 1];
        if ($kind && ! in_array($c->state, ['WAITING_FOR_TARGET', 'WAITING_FOR_CONFIRMATION', 'DIAGNOSTIC_RUNNING', 'CANCELLED', 'CLOSED'], true)) {
            $context['pending_question'] = ['kind' => $kind, 'prompt' => mb_substr(Text::clean($prompt), 0, 300), 'sequence' => $sequence];
        }
        $c->context_json = $context;
    }

    public function answer(Conversation $c, string $body): ?array
    {
        $scope = app(TenantContext::class);
        abort_unless($c->tenant_id === $scope->tenantId && $c->user_id === $scope->userId, 403);
        if (! isset($c->context_json['context_memory_version'])) {
            $last = ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'system')->where('message_type', 'text')->latest('sequence')->first();
            if ($last) {
                $this->question($c, $last->body, $last->sequence);
            }
        }
        $question = $c->context_json['pending_question'] ?? null;
        if (! is_array($question) || $c->status !== 'OPEN' || in_array($c->state, ['WAITING_FOR_TARGET', 'WAITING_FOR_CONFIRMATION', 'DIAGNOSTIC_RUNNING'], true) || $c->sequence - ($question['sequence'] ?? 0) > 16) {
            return null;
        }
        $s = Str::lower(Str::ascii(trim($body)));
        $value = trim(Text::clean($body), " \t\n\r.!?");
        $kind = $question['kind'] ?? '';
        $reply = null;
        if (in_array($kind, ['waiting_duration', 'onset'], true) && preg_match('/^(?:(?:ha|faz|uns|cerca de) )?(\d{1,4}) (minutos?|horas?|dias?|semanas?|mes(?:es)?|anos?)[.! ]*$/', $s, $m)) {
            $duration = $m[1].' '.$m[2];
            $reply = $kind === 'waiting_duration' ? 'Você está esperando resposta há '.$duration.'. Chegou a receber algum retorno nesse período?' : 'Isso vem acontecendo há '.$duration.'. Afeta todos os clientes ou apenas alguns?';
        } elseif (in_array($kind, ['waiting_duration', 'onset'], true) && preg_match('/^(?:desde (?:ontem|hoje)|comecou (?:ontem|hoje)|ontem|hoje)[.! ]*$/', $s)) {
            $when = str_contains($s, 'ontem') ? 'ontem' : 'hoje';
            $reply = $kind === 'waiting_duration' ? 'Você está aguardando desde '.$when.'. Houve algum retorno nesse período?' : 'Isso começou '.$when.'. Afeta todos os clientes ou apenas alguns?';
        } elseif ($kind === 'affected' && preg_match('/^(?:(?:so|apenas|somente) )?(?:alguns|algumas|todos|todas)(?: os| as)?(?: clientes| usuarios)?[.! ]*$/', $s)) {
            $some = (bool) preg_match('/alguns|algumas/', $s);
            $reply = $some ? 'A dificuldade afeta apenas alguns clientes. O que eles têm em comum, como região ou tipo de acesso?' : 'A dificuldade afeta todos os clientes que você mencionou. Ela começou hoje ou já acontecia antes?';
        } elseif ($kind === 'reply_received' && preg_match('/^(?:nao(?: respondem| responderam| respondeu)?|sem (?:retorno|resposta)|ninguem responde)[.! ]*$/', $s)) {
            $reply = 'Você continua sem retorno. O que estava tentando resolver com esse contato?';
        } elseif ($kind === 'symptom' && preg_match('/^(?:nao respondem|nao responde|cai|lento|lenta|so (?:cai|trava)|vai e volta)[.! ]*$/', $s)) {
            $reply = 'O sintoma que você descreveu é: '.$value.'. Isso começou hoje ou já acontecia antes?';
        }
        if (! $reply) {
            return null;
        }

        return ['kind' => $kind, 'value' => mb_substr($value, 0, 120), 'question_sequence' => $question['sequence'], 'question' => $question['prompt'], 'reply' => $reply];
    }

    public function acceptable(string $text, array $answer): bool
    {
        $s = Str::lower(Str::ascii($text));
        $value = Str::lower(Str::ascii($answer['value']));
        if (preg_match('/pode continuar|acompanhando o contexto|estou acompanhando|sinto muito|imagino como|entendo sua frustracao/', $s)) {
            return false;
        }
        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:ms\b|mbps\b|gbps\b|hops\b|saltos\b|%)/', $s) || preg_match('/rede (?:toda )?(?:esta )?(?:normal|saudavel)|\b(?:medi|testei|executei)\b/', $s)) {
            return false;
        }
        if (preg_match('/(\d+) (minutos?|horas?|dias?|semanas?|mes(?:es)?|anos?)/', $value, $m)) {
            return (bool) preg_match('/\b'.preg_quote($m[1], '/').'\s+'.preg_quote($m[2], '/').'\b/', $s);
        }
        if (preg_match('/ontem|hoje/', $value, $m)) {
            return str_contains($s, $m[0]);
        }
        if ($answer['kind'] === 'affected') {
            return preg_match('/alguns|algumas/', $value) ? (bool) preg_match('/alguns|algumas/', $s) : (bool) preg_match('/todos|todas/', $s);
        }

        return (bool) preg_match('/respost|retorno|respond|sintoma|cai|lenta|lento|trava/', $s);
    }
}
