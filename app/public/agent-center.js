(() => {
'use strict';
const toggle=document.querySelector('[data-nav-toggle]');
function closeNav(){if(document.body.classList.contains('nav-open')&&document.querySelector('#sidebar')?.contains(document.activeElement))toggle?.focus();document.body.classList.remove('nav-open');toggle?.setAttribute('aria-expanded','false');}
toggle?.addEventListener('click',()=>{const open=document.body.classList.toggle('nav-open');toggle.setAttribute('aria-expanded',String(open));});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeNav();});
document.querySelector('main')?.addEventListener('click',closeNav);
const dialog=document.querySelector('#confirm-dialog');let pendingForm=null;
document.addEventListener('submit',e=>{
 const form=e.target;
 if(form instanceof HTMLFormElement && form.dataset.confirm && !form.dataset.confirmed){
  e.preventDefault();pendingForm=form;
  document.querySelector('#confirm-message').textContent=form.dataset.confirm;
  dialog.returnValue="cancel";dialog.showModal();dialog.querySelector('[value="cancel"]').focus();
 }
});
dialog?.addEventListener('close',()=>{const form=pendingForm;pendingForm=null;if(dialog.returnValue==='confirm'&&form){form.dataset.confirmed='1';form.requestSubmit();delete form.dataset.confirmed;}});
for(const panel of document.querySelectorAll('[data-live-panel]')){
 let busy=false,timer=null,failures=0,stopped=false;const interval=Number(panel.dataset.interval)||10000;
 const status=document.querySelector(`[data-refresh-for="${panel.id}"]`);
 const run=async()=>{
  if(stopped)return;
  if(document.hidden||busy||dialog?.open||panel.contains(document.activeElement)||window.getSelection()?.toString()){schedule();return;}
  busy=true;panel.setAttribute('aria-busy','true');const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),8000);
  try{
   const url=new URL(location.href);url.searchParams.set('fragment','1');
   const response=await fetch(url,{signal:controller.signal,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'text/html'}});
   if(response.redirected||response.status===401||response.status===403||response.status===404){stopped=true;throw new Error('session');}
   if(!response.ok||response.headers.get('X-AC-Fragment')!==panel.id)throw new Error('response');
   if(response.headers.get('X-AC-Tenant')!==panel.dataset.tenant){stopped=true;throw new Error('context');}
   const html=await response.text();const opened=[...panel.querySelectorAll('details[open][data-detail-key]')].map(e=>e.dataset.detailKey);
   const template=document.createElement('template');template.innerHTML=html;
   panel.replaceChildren(template.content.cloneNode(true));
   for(const detail of panel.querySelectorAll('details[data-detail-key]'))if(opened.includes(detail.dataset.detailKey))detail.open=true;
   if(status){status.removeAttribute('data-error');status.querySelector('span:last-child').textContent='Atualizado às '+new Date().toLocaleTimeString('pt-BR')+' · atualização automática';}
   failures=0;if(panel.querySelector('[data-terminal]'))stopped=true;
  }catch(error){failures++;if(status){status.setAttribute('data-error','');status.querySelector('span:last-child').textContent=stopped?'Sessão, tenant ou acesso alterado. Recarregue para continuar.':'Não foi possível atualizar. Os últimos dados continuam visíveis; nova tentativa automática.';}}
  finally{clearTimeout(timeout);panel.setAttribute('aria-busy','false');busy=false;schedule();}
 };
 const schedule=()=>{clearTimeout(timer);if(!stopped)timer=setTimeout(run,Math.min(60000,interval*2**Math.min(failures,3)));};
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!stopped){clearTimeout(timer);run();}});
 schedule();
}
})();
