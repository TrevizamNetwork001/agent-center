import {generateIdentity, identityToRecipient} from 'age-encryption';

const button = document.querySelector('[data-backup-keygen]');
const recipientInput = document.querySelector('[name="r2_recipient"]');
const status = document.querySelector('[data-backup-keygen-status]');

button?.addEventListener('click', async () => {
    button.disabled = true;
    let identity;
    try {
        if (!globalThis.isSecureContext) throw new Error('secure-context-required');
        if (recipientInput.value && !window.confirm('Criar uma nova chave? Preserve as chaves anteriores para recuperar backups antigos.')) return;
        identity = await generateIdentity();
        const recipient = await identityToRecipient(identity);
        const text = '# Agent Center recovery identity — keep outside the VPS\n# public key: ' + recipient + '\n' + identity + '\n';
        const url = URL.createObjectURL(new Blob([text], {type: 'application/octet-stream'}));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'agent-center-recovery-' + new Date().toISOString().slice(0,10) + '.agekey';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        recipientInput.value = recipient;
        const acknowledgement = document.querySelector('[name="r2_offsite_ack"]');
        if (acknowledgement) acknowledgement.checked = false;
        status.textContent = 'Chave criada neste navegador. Guarde o arquivo baixado em um cofre e em uma segunda cópia segura. Somente a pública foi preenchida no formulário.';
    } catch {
        status.textContent = 'Não foi possível gerar a chave. Confirme HTTPS e use um navegador atualizado. Nenhuma chave privada foi enviada.';
    } finally {
        identity = undefined;
        button.disabled = false;
    }
});
