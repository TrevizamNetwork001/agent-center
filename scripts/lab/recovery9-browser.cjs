const {chromium}=require('/opt/browser/node_modules/playwright');
const fs=require('fs');
(async()=>{
 const c=JSON.parse(fs.readFileSync('/lab/credentials.json'));const base='http://127.0.0.1:18089';const b=await chromium.launch({headless:true,args:['--no-sandbox']});
 const results=[];const errors=[];const check=(v,n)=>{results.push({name:n,pass:!!v});if(!v)throw Error(n)};
 try{const ctx=await b.newContext({viewport:{width:1366,height:768},reducedMotion:'reduce',colorScheme:'light'});await ctx.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
 await p.goto(base+'/login');await p.locator('[name=email]').fill(c.email);await p.locator('[name=password]').fill(c.password);await Promise.all([p.waitForURL('**/dashboard'),p.getByRole('button',{name:'Entrar',exact:true}).click()]);
 check(await p.locator('h1').textContent()==='Visão geral','native_login');
 await p.goto(base+'/conversations');await p.locator('summary').filter({hasText:'Ativação neste tenant'}).click();await p.locator('[name=enabled]').selectOption('0');await p.getByRole('button',{name:'Salvar ativação'}).click();await p.waitForLoadState('load');
 const pages=[['dashboard','/dashboard'],['agents','/agents'],['agent-detail','/agents/'+c.agent_id],['diagnostics','/diagnostics'],['create','/diagnostics/create'],['history','/diagnostics/history'],['result','/diagnostics/'+c.result_id],['progress','/diagnostics/'+c.progress_id],['profiles','/profiles'],['skills','/skills'],['knowledge','/knowledge'],['knowledge-create','/knowledge/create'],['prompts','/advanced/prompts'],['prompt-create','/advanced/prompts/create'],['llm','/advanced/llm'],['audit','/audit'],['users','/users'],['operations','/operations'],['conversation','/conversations']];
 for(const [size,width,height] of [['desktop',1366,768],['wide',1920,1080],['tablet',768,1024],['mobile',390,844]]){
  await p.setViewportSize({width,height});
  for(const theme of ['light','dark']){
   await p.evaluate(t=>{localStorage.setItem('agent-center.theme.'+document.body.dataset.themeUser,t)},theme);
   for(const [name,url] of pages){let r=await p.goto(base+url);check(r.status()===200,size+'-'+theme+'-'+name+'-200');await p.waitForFunction(t=>document.documentElement.dataset.theme===t,theme);check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),size+'-'+theme+'-'+name+'-overflow');if(['desktop','mobile'].includes(size))await p.screenshot({path:'/evidence/'+size+'-'+theme+'-'+name+'.png',fullPage:true});}
  }
 }
 await p.goto(base+'/dashboard');await p.setViewportSize({width:390,height:844});const toggle=p.locator('[data-nav-toggle]');await toggle.click();check(await toggle.getAttribute('aria-expanded')==='true','mobile_nav_open');await p.screenshot({path:'/evidence/mobile-sidebar-open.png',fullPage:true});await p.keyboard.press('Escape');check(await toggle.getAttribute('aria-expanded')==='false','mobile_nav_escape');
 await p.goto(base+'/diagnostics/'+c.progress_id);const cancel=p.locator('form[data-confirm]');if(await cancel.count()){await cancel.first().locator('button').click();check(await p.locator('#confirm-dialog').isVisible(),'cancel_dialog');await p.screenshot({path:'/evidence/mobile-confirmation.png'});await p.locator('#confirm-dialog [value=cancel]').click();}
 await p.goto(base+'/conversations');check(await p.getByText('Conversation desabilitada',{exact:true}).isVisible(),'conversation_flag_disabled');await p.locator('summary').filter({hasText:'Ativação neste tenant'}).click();await p.locator('[name=enabled]').selectOption('1');await Promise.all([p.waitForLoadState('load'),p.getByRole('button',{name:'Salvar ativação'}).click()]);await p.getByRole('button',{name:'Nova conversa'}).click();await p.waitForURL('**/conversations/*');await p.screenshot({path:'/evidence/mobile-dark-conversation-enabled.png',fullPage:true});
 await p.locator('[name=body]').fill('Obrigado, pode encerrar.');await p.getByRole('button',{name:'Enviar',exact:true}).click();await p.waitForLoadState('load');await p.screenshot({path:'/evidence/conversation-close.png',fullPage:true});
 await p.goto(base+'/dashboard');const button=p.locator('[data-theme-toggle]');await button.click();const t=await p.locator('html').getAttribute('data-theme');await p.reload();check(await p.locator('html').getAttribute('data-theme')===t,'theme_reload_persistence');await p.locator('.skip-link').focus();await p.keyboard.press('Enter');check(await p.locator('#main').evaluate(e=>e===document.activeElement),'keyboard_skip_link');check(errors.length===0,'no_javascript_errors');
 await p.locator('.account-menu summary').click();await p.getByRole('button',{name:'Sair da sessão'}).click();await p.waitForURL('**/login');check(true,'logout');
 fs.writeFileSync('/evidence/browser.json',JSON.stringify({pass:true,checks:results,scope:'isolated_postgresql_synthetic_fixtures',external_requests_blocked:true},null,2));console.log('Chromium PASS: '+results.length+' checks');
 }finally{await b.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1});
