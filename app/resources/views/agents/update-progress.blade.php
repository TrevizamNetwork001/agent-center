@php
    $update = $operations->first(fn ($operation) => $operation->kind === 'update');
    $stages = [
        'PRECHECK_REQUESTED' => [0, 'Aguardando verificação no host'],
        'PRECHECKING' => [0, 'Baixando e verificando pacote e compatibilidade'],
        'PLANNED' => [1, 'Verificação concluída · aguardando sua confirmação'],
        'APPROVED' => [2, 'Plano confirmado · aguardando instalação'],
        'RUNNING' => [2, 'Instalação em andamento'],
        'COMPLETED' => [4, 'Atualização concluída'],
    ];
    if ($update?->reason === 'restart') {
        $stages['APPROVED'] = [2, 'Verificação concluída · aguardando reinício'];
        $stages['RUNNING'] = [2, 'Reiniciando serviço do Agent'];
        $stages['COMPLETED'] = [4, 'Serviço reiniciado e comunicação confirmada'];
    }
    $stage = $update ? ($stages[$update->status] ?? null) : null;
@endphp
@if($stage)
    <div class="agent-update-progress" data-agent-update-progress data-operation="{{$update->id}}" data-state="{{$update->status}}" data-percent="{{$stage[0] * 25}}" role="status" aria-live="polite">
        <div class="agent-update-progress-heading"><strong>{{$stage[1]}}</strong><span data-update-percent>{{$stage[0] * 25}}%</span></div>
        <div id="agent-update-progress" class="agent-update-progress-track" role="progressbar" aria-label="Progresso das etapas de atualização" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{$stage[0] * 25}}">
            <span data-update-fill style="width:{{$stage[0] * 25}}%"></span>
        </div>
    </div>
@elseif($update && in_array($update->status, ['FAILED', 'UNCERTAIN'], true))
    @php($reason = [
        'release_download_failed' => 'Não foi possível baixar o pacote. Confira a conexão com a Central e tente novamente.',
        'invalid_release' => 'O pacote solicitado não passou na validação.',
        'self_check_permission_denied' => 'O serviço não teve permissão para concluir a verificação.',
        'self_check_privilege_failed' => 'O serviço não conseguiu validar o pacote com o usuário do Agent.',
        'self_check_timeout' => 'A verificação do pacote excedeu o prazo.',
        'self_check_exec_failed' => 'O pacote não passou na verificação de execução.',
        'self_check_invalid_output' => 'O pacote retornou uma verificação inválida.',
        'rollback_failed' => 'A recuperação exige revisão do administrador antes de continuar.',
        'service_restart_failed' => 'O serviço precisa ser verificado antes de continuar.',
        'postcheck_failed' => ($update->evidence['rollback'] ?? false) ? 'A nova versão não ficou pronta. A versão anterior foi restaurada.' : 'A nova versão não confirmou que está pronta.',
    ][$update->error] ?? 'Solicite a revisão do administrador antes de tentar novamente.')
    <p role="status">Atualização interrompida. {{$reason}}</p>
@endif
