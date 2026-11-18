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
const sidebar=document.querySelector('#sidebar');
const mobileNav=window.matchMedia('(max-width: 760px)');
const inertBackground=new Map();
function closeNav(){
 const returnFocus=mobileNav.matches&&document.body.classList.contains('nav-open')&&sidebar?.contains(document.activeElement);
 document.body.classList.remove('nav-open');toggle?.setAttribute('aria-expanded','false');toggle?.setAttribute('aria-label','Abrir navegação');
 inertBackground.forEach((previous,element)=>{element.inert=previous;});inertBackground.clear();
 if(returnFocus)toggle?.focus();
}
toggle?.addEventListener('click',()=>{
 if(document.body.classList.contains('nav-open')){closeNav();return;}
 if(!mobileNav.matches||!sidebar)return;
 document.body.classList.add('nav-open');toggle.setAttribute('aria-expanded','true');toggle.setAttribute('aria-label','Fechar navegação');
 document.querySelectorAll('main,.page-footer,.user-menu,.topbar-context > :not([data-nav-toggle])').forEach(element=>{inertBackground.set(element,element.inert);element.inert=true;});
 (sidebar.querySelector('[aria-current="page"]')||sidebar.querySelector('a[href]'))?.focus();
});
document.addEventListener('keydown',e=>{
 if(!document.body.classList.contains('nav-open'))return;
 if(e.key==='Escape'){e.preventDefault();closeNav();return;}
 if(e.key!=='Tab')return;
 const controls=[...sidebar.querySelectorAll('a[href],button:not(:disabled),[tabindex="0"]'),toggle].filter(element=>element.getClientRects().length);
 const index=controls.indexOf(document.activeElement);
 if(e.shiftKey&&(index<=0)){e.preventDefault();controls.at(-1)?.focus();}
 else if(!e.shiftKey&&(index===-1||index===controls.length-1)){e.preventDefault();controls[0]?.focus();}
});
document.addEventListener('click',e=>{if(document.body.classList.contains('nav-open')&&!sidebar?.contains(e.target)&&!toggle?.contains(e.target))closeNav();});
mobileNav.addEventListener('change',closeNav);
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

// MTR availability is a server-confirmed capability, never a catalog toggle alone.
(() => {
 const select=document.querySelector('[data-diagnostic-agent]');
 if(!select)return;
 const box=document.querySelector('[data-requires-mtr]');
 const profile=document.querySelector('[data-diagnostic-profile]');
 const status=document.querySelector('[data-mtr-availability]');
 const apply=()=>{
  const ready=select.selectedOptions[0]?.dataset.mtrReady==='1';
  if(box){box.disabled=!ready;if(!ready)box.checked=false;}
  profile?.querySelectorAll('[data-profile-requires-mtr="1"]').forEach(option=>{option.disabled=!ready;if(!ready&&option.selected)profile.value='';});
  if(status)status.textContent=ready?'MTR disponível neste Agent; ACL-base e teto administrativo continuam obrigatórios.':'MTR indisponível neste Agent. Aguarde o runtime 0.6.2, helper seguro e confirmação da capability.';
 };
 select.addEventListener('change',apply);apply();
})();
