(() => {
    'use strict';
    // Only the inventory edit/create page loads this script. Inline errors remain visible.
    const notice = document.querySelector('#main > .feedback[role="alert"], #main > .feedback[role="status"]');
    if (notice) {
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'button-secondary small-button';
        close.textContent = 'Fechar aviso';
        close.setAttribute('aria-label', 'Fechar aviso de validação');
        close.addEventListener('click', () => { notice.hidden = true; });
        notice.append(close);
        let timer;
        const schedule = () => { clearTimeout(timer); timer = setTimeout(() => { notice.hidden = true; }, 10000); };
        notice.addEventListener('mouseenter', () => clearTimeout(timer));
        notice.addEventListener('focusin', () => clearTimeout(timer));
        notice.addEventListener('mouseleave', schedule);
        notice.addEventListener('focusout', schedule);
        schedule();
    }
    document.querySelector('form [aria-invalid="true"]')?.focus();
})();
