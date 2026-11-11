(() => {
    'use strict';
    const root = document.querySelector('[data-tg-wizard]');
    if (root) {
        const panels = [...root.querySelectorAll('[data-tg-panel]')];
        const sections = [...root.querySelectorAll('[data-tg-section]')];
        const showSection = (value) => {
            sections.forEach(item => { item.hidden = item.dataset.tgSection !== value; if (item.dataset.tgSection === 'operations' && value === 'operations') item.open = true; });
            root.querySelectorAll('[data-tg-section-nav]').forEach(item => {
                if (item.dataset.tgSectionNav === value) item.setAttribute('aria-current', 'page');
                else item.removeAttribute('aria-current');
            });
        };
        const sectionFromHash = () => showSection(location.hash === '#company-groups' ? 'company' : location.hash === '#telegram-operations' ? 'operations' : 'config');
        sectionFromHash();
        root.querySelectorAll('[data-tg-section-nav]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); history.replaceState(null, '', link.getAttribute('href')); showSection(link.dataset.tgSectionNav); }));
        window.addEventListener('hashchange', sectionFromHash);

        const show = (value) => {
            showSection('config');
            const panel = panels.find(item => item.dataset.tgPanel === String(value));
            if (!panel) return;
            panels.forEach(item => { item.hidden = item !== panel; });
            const activeLink = root.querySelector(`[data-tg-nav="${value}"]`);
            const track = activeLink?.parentElement;
            if (track && track.scrollWidth > track.clientWidth) {
                track.scrollLeft += activeLink.getBoundingClientRect().left - track.getBoundingClientRect().left - 8;
            }

            root.querySelectorAll('[data-tg-nav]').forEach(item => {
                if (item.dataset.tgNav === String(value)) item.setAttribute('aria-current', 'step');
                else item.removeAttribute('aria-current');
            });
        };
        const hash = location.hash.match(/^#tg-step-([1-5])$/);
        show(hash ? hash[1] : root.dataset.currentStep);
        sectionFromHash();
        root.querySelectorAll('[data-tg-nav], [data-tg-go]').forEach(link => link.addEventListener('click', () => show(link.dataset.tgNav || link.dataset.tgGo)));
        window.addEventListener('hashchange', () => { const next = location.hash.match(/^#tg-step-([1-5])$/); if (next) show(next[1]); });
    }
    document.querySelectorAll('[data-tg-reply-mode]').forEach(select => {
        const label = select.form?.querySelector('[data-tg-shared-ack]');
        const checkbox = label?.querySelector('input');
        if (!label || !checkbox) return;
        const update = () => { const shared = select.value === 'group'; label.hidden = !shared; checkbox.required = shared; checkbox.disabled = !shared; };
        select.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-tg-copy-text], [data-tg-copy-target]').forEach(button => button.addEventListener('click', async () => {
        const feedback = document.querySelector('[data-tg-copy-feedback]');
        const value = button.dataset.tgCopyText || document.getElementById(button.dataset.tgCopyTarget)?.textContent;
        if (!value) return;
        try {
            await navigator.clipboard.writeText(value.trim());
            if (feedback) feedback.textContent = 'Copiado. Agora cole a mensagem no Telegram.';
        } catch {
            if (feedback) feedback.textContent = 'Não foi possível copiar automaticamente. Selecione o comando e copie manualmente.';
        }
    }));
})();
