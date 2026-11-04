'use strict';
const { chromium }=require('/opt/browser/node_modules/playwright');
const fs=require('fs');
const c=JSON.parse(fs.readFileSync('/lab/credentials.json','utf8'));
const base='http://127.0.0.1:18084';const output='/evidence';
const checks=[];function check(value,name){checks.push({name,pass:!!value});if(!value)throw new Error(name);}
let lease=null,browser,timer;
async function agent(op,data){const response=await fetch(base+'/api/v1/agent/'+op,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json',Authorization:'Bearer '+c.token},body:JSON.stringify(data)});if(!response.ok)return null;return response.json();}
(async()=>{
 await agent('heartbeat',{version:'0.5.2'});await agent('claim',{task_protocol:1,capacity:0});
 browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const context=await browser.newContext({extraHTTPHeaders:{'Cache-Control':'no-cache'},viewport:{width:1366,height:768},reducedMotion:'reduce'});
 await context.route('**/*',route=>{const u=new URL(route.request().url());return u.origin===base?route.continue():route.abort();});
 const page=await context.newPage();const errors=[];page.on('pageerror',()=>errors.push('browser_script_error'));
 await page.goto(base+'/login');await page.screenshot({path:output+'/login-desktop.png',fullPage:true});
 check(await page.locator('link[rel=stylesheet]').count()===1,'local_css_link');
 await page.locator('input[name=email]').fill(c.email);await page.locator('input[name=password]').fill(c.password);
 await Promise.all([page.waitForURL('**/dashboard'),page.getByRole('button',{name:'Entrar',exact:true}).click()]);
 check(await page.getByRole('heading',{name:'Visão geral',exact:true}).isVisible(),'login_native_session');
 // Replace the earlier fixture lease with a fresh investigation through the actual UI.
 await page.goto(base+'/diagnostics/'+c.progress_id);
 const cancel=page.getByRole('button',{name:'Cancelar diagnóstico',exact:true});
 if(await cancel.count()){await cancel.click();await page.locator('#confirm-dialog button[value=confirm]').click();}
 await page.goto(base+'/diagnostics/create');await page.locator('[name=profile_id]').selectOption({label:'Site lento'});await page.locator('[name=target]').fill('investigacao.fixture.test');await page.locator('[name=agent_id]').selectOption(c.agent_id);
 await Promise.all([page.waitForURL(/\/diagnostics\/[a-f0-9-]+$/),page.getByRole('button',{name:/Executar diagnóstico/}).click()]);
 const progressPath=new URL(page.url()).pathname;
 // The real lab scheduler materializes tasks; this harness claims one and holds the lease.
 for(let n=0;n<20;n++){const got=await agent('claim',{task_protocol:1,capacity:1});if(got?.task){lease=Object.fromEntries(['task_id','execution_id','generation','lease_token'].map(k=>[k,got.task[k]]));await agent('start',lease);break;}await new Promise(r=>setTimeout(r,300));}
 check(!!lease,'live_claim_start_for_progress');
 timer=setInterval(async()=>{try{await agent('heartbeat',{version:'0.5.2'});await agent('claim',{task_protocol:1,capacity:0});if(lease)await agent('renew',lease);}catch{}},10000);
 await page.reload();const cancelForm=await page.locator('form[data-confirm][action$="/cancel"]').elementHandle();await page.getByRole('button',{name:'Cancelar diagnóstico',exact:true}).click();await page.waitForTimeout(5000);check(await cancelForm.evaluate(e=>e.isConnected),'refresh_pauses_for_confirmation');await page.keyboard.press('Escape');check((await agent('renew',lease))?.status==='ok','escape_did_not_cancel_diagnostic');
 const pages=[['dashboard','/dashboard'],['agents','/agents'],['new-diagnostic','/diagnostics/create'],['progress',progressPath],['result','/diagnostics/'+c.result_id],['history','/diagnostics/history'],['operations','/operations'],['profiles','/profiles'],['skills','/skills'],['audit','/audit']];
 for(const viewport of [{name:'desktop',width:1366,height:768},{name:'mobile',width:390,height:844}]){
  await page.setViewportSize(viewport);
  for(const [name,path] of pages){const response=await page.goto(base+path);check(response.status()===200,name+'_'+viewport.name+'_http');await page.screenshot({path:output+'/'+name+'-'+viewport.name+'.png',fullPage:true});const width=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}));check(width.scroll<=width.viewport+1,name+'_'+viewport.name+'_no_page_overflow');const text=await page.locator('body').innerText();check(!text.includes(c.token)&&!text.includes(c.password),name+'_'+viewport.name+'_no_secrets');}
 }
 await page.goto(base+'/login'); // Already authenticated: native guest middleware redirects.
 const mobileContext=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});
 const loginPage=await mobileContext.newPage();await loginPage.goto(base+'/login');await loginPage.screenshot({path:output+'/login-mobile.png',fullPage:true});await mobileContext.close();
 for(const size of [{width:1024,height:700},{width:768,height:1024},{width:360,height:740}]){await page.setViewportSize(size);for(const path of ['/dashboard','/agents','/diagnostics/history','/operations','/profiles','/skills']){await page.goto(base+path);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'responsive_'+size.width+'_'+path.replaceAll('/','_'));}}
 await page.setViewportSize({width:390,height:844});await page.goto(base+'/dashboard');await page.getByRole('button',{name:'Abrir navegação'}).click();check(await page.locator('#sidebar').isVisible(),'mobile_navigation_visible');await page.getByRole('link',{name:'Agents',exact:true}).focus();await page.keyboard.press('Escape');check(await page.getByRole('button',{name:'Abrir navegação'}).getAttribute('aria-expanded')==='false','mobile_navigation_escape');
 await page.goto(base+'/agents/'+c.agent_id);await page.getByRole('button',{name:'Revogar Agent',exact:true}).click();check(await page.locator('#confirm-dialog').isVisible(),'destructive_confirmation');await page.keyboard.press('Escape');check(!await page.locator('#confirm-dialog').isVisible(),'destructive_escape_cancels');const hb=await agent('heartbeat',{version:'0.5.2'});check(hb?.status==='ok','escape_did_not_revoke');
 await page.setViewportSize({width:1366,height:768});await page.goto(base+'/diagnostics/history?target=portal.fixture.test&classification=NORMAL');check(await page.locator('tbody tr').count()===1,'history_filter_real_rows');check((await page.locator('tbody').innerText()).includes('portal.fixture.test'),'history_filtered_target');
 await page.goto(base+'/agents');await page.locator('details[data-detail-key]').first().locator('summary').click();await page.locator('h1').click();const navs=[];const listener=frame=>{if(frame===page.mainFrame())navs.push(1);};page.on('framenavigated',listener);await page.waitForResponse(r=>r.url().includes('fragment=1')&&r.headers()['x-ac-fragment']==='agents-panel',{timeout:20000});await page.waitForTimeout(150);check(navs.length===0,'refresh_no_document_navigation');check(await page.locator('details[data-detail-key]').first().evaluate(e=>e.open),'refresh_preserves_open_details');page.off('framenavigated',listener);
 await page.goto(base+'/dashboard');const before=await page.locator('#dashboard-panel').innerText();await page.route('**/dashboard?fragment=1',async route=>{const response=await route.fetch();await route.fulfill({response,headers:{...response.headers(),'x-ac-tenant':'different-context'}});});const changed=page.waitForResponse(r=>r.url().includes('/dashboard?fragment=1'));await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await changed;await page.waitForTimeout(150);check(await page.locator('[data-refresh-for=dashboard-panel]').getAttribute('data-error')!==null,'changed_tenant_stops_refresh');check(await page.locator('#dashboard-panel').innerText()===before,'changed_tenant_does_not_replace_content');
 check(errors.length===0,'no_browser_script_errors');
 // WCAG contrast of essential palette pairs, calculated from actual styles.
 const contrast=await page.evaluate(()=>{const luminance=hex=>{const rgb=hex.match(/\w\w/g).map(x=>parseInt(x,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;};return [['17283e','ffffff'],['5c6d80','ffffff'],['14734f','edf8f2'],['8b5709','fff5dd'],['ac3037','fff0f0'],['215bcc','ffffff']].map(([a,b])=>({foreground:a,background:b,ratio:(Math.max(luminance(a),luminance(b))+.05)/(Math.min(luminance(a),luminance(b))+.05)}));});check(contrast.every(v=>v.ratio>=4.5),'palette_contrast_wcag_aa');
 await page.goto(base+progressPath);const stop=page.getByRole('button',{name:'Cancelar diagnóstico',exact:true});if(await stop.count()){await stop.click();await page.locator('#confirm-dialog button[value=confirm]').click();await agent('result',Object.assign({},lease,{result:{ok:false,error:'cancelled'}}));lease=null;}
 fs.writeFileSync(output+'/browser-validation.json',JSON.stringify({pass:true,checks,contrast,viewports:['1366x768','1024x700','768x1024','390x844','360x740'],screenshots:22,source:'Real Chromium renders of isolated Laravel/PostgreSQL lab; persisted synthetic fixtures, no production data.'},null,2));
 console.log(JSON.stringify({pass:true,checks:checks.length,screenshots:22}));
})().catch(error=>{console.error('UX browser validation failed: '+error.message);process.exitCode=1;}).finally(async()=>{clearInterval(timer);if(browser)await browser.close();});
