const {chromium}=require('/opt/browser/node_modules/playwright');
const fs=require('fs');const assert=require('assert/strict');
const root=process.argv[2]||'/release/stage';
const script=fs.readFileSync(root+'/app/public/agent-center.js','utf8');
const css=fs.readFileSync(root+'/app/public/agent-center.css','utf8');
const url='https://diagnostic-ui.test/diagnostics/fixture';
const fragment=done=>`${done?'<span data-terminal hidden></span><h2>Resultado NORMAL</h2>':'<h2>Coletando evidências</h2><p class="diagnostic-wait" role="status"><span class="diagnostic-spinner"></span>Em execução — aguarde.</p><p id="counts">1 de 2 verificações finalizadas.</p>'}<details data-detail-key="DNS"><summary>Detalhes DNS</summary><p>Verificações</p></details><input aria-label="Anotação" value="teste">`;
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});try{
for(const scenario of ['summary','selection','editing','tenant']){
 const page=await browser.newPage();let polls=0,navigations=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',route=>{
  const u=new URL(route.request().url());if(u.origin!==new URL(url).origin)return route.abort();
  if(u.searchParams.has('fragment'))return route.fulfill({headers:{'Content-Type':'text/html','X-AC-Fragment':'diagnostic-panel','X-AC-Tenant':scenario==='tenant'?'2':'1'},body:fragment(++polls>=2)});
  navigations++;return route.fulfill({contentType:'text/html',body:`<html><head><style>${css}</style></head><body><main><h1>Diagnóstico</h1><div id="diagnostic-panel" data-live-panel data-interval="150" data-tenant="1">${fragment(false)}</div><p data-refresh-for="diagnostic-panel"><span></span><span>Aguardando</span></p></main><script>${script}</script></body></html>`});
 });await page.goto(url);
 if(scenario==='summary'){await page.locator('summary').click();await page.locator('summary').focus();}
 if(scenario==='selection')await page.evaluate(()=>{const r=document.createRange();r.selectNodeContents(document.querySelector('#counts'));getSelection().removeAllRanges();getSelection().addRange(r);});
 if(scenario==='editing'){await page.getByLabel('Anotação').focus();const beforeEditing=polls;await page.waitForTimeout(450);assert.equal(polls,beforeEditing);await page.evaluate(()=>document.activeElement.blur());}
 if(scenario==='tenant'){await page.waitForTimeout(750);assert.equal(await page.getByRole('heading',{name:'Resultado NORMAL'}).count(),0);assert.equal(polls,1);}
 else{await page.getByRole('heading',{name:'Resultado NORMAL'}).waitFor({timeout:5000});const completedPolls=polls;await page.waitForTimeout(400);assert.equal(polls,completedPolls);if(scenario==='summary'){assert.equal(await page.locator('details').getAttribute('open'),'');assert.equal(await page.locator('summary').evaluate(e=>e===document.activeElement),true);}}
 assert.equal(navigations,1,'completion must not need F5');assert.deepEqual(errors,[]);await page.close();
}
const page=await browser.newPage();await page.emulateMedia({reducedMotion:'reduce'});await page.setContent(`<style>${css}</style><span class="diagnostic-spinner"></span>`);assert.equal(await page.locator('.diagnostic-spinner').evaluate(e=>getComputedStyle(e).animationName),'none');await page.close();
console.log('PASS: automatic completion with summary focus and selected text; focus/open details preserved; editing respected; tenant guard; terminal polling stops; reduced motion');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
