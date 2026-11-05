(() => {
'use strict';
const themeButton=document.querySelector('[data-theme-toggle]');
const userKey=document.body.dataset.themeUser;
const themeKey='agent-center.theme.'+(userKey||'guest');
const systemTheme=window.matchMedia('(prefers-color-scheme: dark)');
let theme='system';
try{const saved=localStorage.getItem(themeKey);if(['light','dark','system'].includes(saved))theme=saved;}catch{}
function applyTheme(){document.documentElement.dataset.theme=theme==='system'?(systemTheme.matches?'dark':'light'):theme;if(themeButton){const labels={system:'sistema',light:'claro',dark:'escuro'};const next={system:'light',light:'dark',dark:'system'}[theme];const label='Tema: '+labels[theme]+'. Ativar tema '+labels[next]+'.';themeButton.setAttribute('aria-label',label);themeButton.title=label;themeButton.querySelectorAll('[data-theme-icon]').forEach(icon=>{icon.hidden=icon.dataset.themeIcon!==document.documentElement.dataset.theme;});}}
applyTheme();systemTheme.addEventListener('change',applyTheme);
themeButton?.addEventListener('click',()=>{theme=({system:'light',light:'dark',dark:'system'}[theme]);try{localStorage.setItem(themeKey,theme);}catch{}applyTheme();});

const toggle=document.querySelector('[data-nav-toggle]');
function closeNav(){if(document.body.classList.contains('nav-open')&&document.querySelector('#sidebar')?.contains(document.activeElement))toggle?.focus();document.body.classList.remove('nav-open');toggle?.setAttribute('aria-expanded','false');}
toggle?.addEventListener('click',()=>{const open=document.body.classList.toggle('nav-open');toggle.setAttribute('aria-expanded',String(open));});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeNav();});
document.querySelector('main')?.addEventListener('click',closeNav);
document.addEventListener('input',e=>{const form=e.target.closest?.('form[data-preserve-edits]');if(form)form.dataset.dirty='1';});
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
 let busy=false,timer=null,failures=0,stopped=false,reloadScheduled=false;const interval=Number(panel.dataset.interval)||10000;
 const status=document.querySelector(`[data-refresh-for="${panel.id}"]`);
 const isEditing=()=>!!panel.querySelector('form[data-preserve-edits][data-dirty]')||panel.contains(document.activeElement)&&(document.activeElement.matches('input,textarea,select')||document.activeElement.isContentEditable);
 const finishUpdate=()=>{
  if(reloadScheduled)return;
  const target=new URL(panel.dataset.completionUrl,location.href);
  if(target.origin!==location.origin)return;
  reloadScheduled=true;stopped=true;
  setTimeout(()=>location.assign(target.href),1400);
 };
 if(panel.dataset.completionUrl&&panel.querySelector('[data-agent-update-progress]')?.dataset.state==='COMPLETED')finishUpdate();
 const run=async()=>{
  if(stopped)return;
  if(document.hidden||busy||dialog?.open||isEditing()){schedule();return;}
  busy=true;panel.setAttribute('aria-busy','true');const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),8000);
  try{
   const url=new URL(location.href);url.searchParams.set('fragment','1');
   const response=await fetch(url,{signal:controller.signal,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'text/html'}});
   if(response.redirected||response.status===401||response.status===403||response.status===404){stopped=true;throw new Error('session');}
   if(!response.ok||response.headers.get('X-AC-Fragment')!==panel.id)throw new Error('response');
   if(response.headers.get('X-AC-Tenant')!==panel.dataset.tenant){stopped=true;throw new Error('context');}
   const html=await response.text();if(dialog?.open||isEditing())return;const opened=[...panel.querySelectorAll('details[open][data-detail-key]')].map(e=>e.dataset.detailKey);
   const active=panel.contains(document.activeElement)?document.activeElement:null;
   const focusedDetail=active?.matches('summary')?active.closest('details[data-detail-key]')?.dataset.detailKey:null;
   const focusedLink=active?.matches('a[href]')?active.getAttribute('href'):null;

   const previousUpdate=panel.querySelector('[data-agent-update-progress]');
   const previous=previousUpdate?{operation:previousUpdate.dataset.operation,state:previousUpdate.dataset.state,percent:Number(previousUpdate.dataset.percent)}:null;
   const template=document.createElement('template');template.innerHTML=html;
   panel.replaceChildren(template.content.cloneNode(true));
   const update=panel.querySelector('[data-agent-update-progress]');
   if(update?.dataset.state==='COMPLETED'&&panel.dataset.completionUrl)finishUpdate();
   if(update&&previous&&update.dataset.operation===previous.operation){
    const fill=update.querySelector('[data-update-fill]');const percent=Number(update.dataset.percent);
    if(fill&&Number.isFinite(previous.percent)&&Number.isFinite(percent)&&percent>=0&&percent<=100){
     fill.style.transition='none';fill.style.width=previous.percent+'%';
     requestAnimationFrame(()=>requestAnimationFrame(()=>{if(fill.isConnected){fill.style.transition='';fill.style.width=percent+'%';}}));
    }
    if(update.dataset.state==='COMPLETED'&&previous.state!=='COMPLETED'&&!reloadScheduled){
     reloadScheduled=true;stopped=true;
     setTimeout(()=>{if(update.isConnected&&update.dataset.state==='COMPLETED')location.reload();},1400);
    }
   }
   for(const detail of panel.querySelectorAll('details[data-detail-key]')){
    if(opened.includes(detail.dataset.detailKey))detail.open=true;
    if(focusedDetail===detail.dataset.detailKey)detail.querySelector('summary')?.focus({preventScroll:true});
   }
   if(focusedLink)[...panel.querySelectorAll('a[href]')].find(link=>link.getAttribute('href')===focusedLink)?.focus({preventScroll:true});
   if(status){status.removeAttribute('data-error');status.querySelector('span:last-child').textContent='Atualizado às '+new Date().toLocaleTimeString('pt-BR',{timeZone:'America/Sao_Paulo'})+' · atualização automática';}
   failures=0;if(panel.querySelector('[data-terminal]'))stopped=true;
  }catch(error){failures++;if(status){status.setAttribute('data-error','');status.querySelector('span:last-child').textContent=stopped?'Sessão, tenant ou acesso alterado. Recarregue para continuar.':'Não foi possível atualizar. Os últimos dados continuam visíveis; nova tentativa automática.';}}
  finally{clearTimeout(timeout);panel.setAttribute('aria-busy','false');busy=false;schedule();}
 };
 const schedule=()=>{clearTimeout(timer);if(!stopped)timer=setTimeout(run,Math.min(60000,interval*2**Math.min(failures,3)));};
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!stopped){clearTimeout(timer);run();}});
 schedule();
}
})();
