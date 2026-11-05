(() => {
'use strict';
const root=document.documentElement;
const select=document.querySelector('[data-theme-select]');
const media=window.matchMedia('(prefers-color-scheme: dark)');
const key='agent-center.install.theme';
let mode='system';
try{const saved=localStorage.getItem(key);if(['system','light','dark'].includes(saved))mode=saved;}catch{}
function apply(){root.dataset.theme=mode==='system'?(media.matches?'dark':'light'):mode;if(select)select.value=mode;}
apply();media.addEventListener('change',()=>{if(mode==='system')apply();});
select?.addEventListener('change',()=>{if(!['system','light','dark'].includes(select.value))return;mode=select.value;try{localStorage.setItem(key,mode);}catch{}apply();});
document.querySelector('[data-copy-command]')?.addEventListener('click',async()=>{
 const command=document.querySelector('#install-command');const feedback=document.querySelector('[data-copy-feedback]');
 try{await navigator.clipboard.writeText(command.textContent);feedback.textContent='Comandos copiados. Cole no terminal da máquina do provedor.';}
 catch{feedback.textContent='Não foi possível copiar automaticamente. Selecione e copie as duas linhas do bloco.';}
});
})();
