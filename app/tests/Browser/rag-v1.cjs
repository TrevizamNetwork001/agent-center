const { chromium } = require('/opt/browser/node_modules/playwright');
const fs=require('fs');
(async()=>{
 const demo=JSON.parse(fs.readFileSync('/lab/demo.json')); const password=fs.readFileSync('/lab/password','utf8').trim();
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const results=[];
 for(const width of [1440,390]) for(const theme of ['light','dark']) {
  const context=await browser.newContext({viewport:{width,height:1100},colorScheme:theme});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://rag-v1-web:8000/login');await page.locator('input[name=email]').fill('admin-a@rag.test');await page.locator('input[name=password]').fill(password);await page.getByRole('button',{name:'Entrar',exact:true}).click();await page.waitForURL('**/dashboard');
  await page.goto('http://rag-v1-web:8000/knowledge/rag');await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
  await page.locator('input[name=question]').fill('Como devo investigar um site lento?');await page.getByRole('button',{name:'Buscar sem LLM'}).click();await page.waitForLoadState('networkidle');
  if(!(await page.locator('main').innerText()).includes('Testes iniciais')) throw Error('Missing retrieval result');
  await page.screenshot({path:`/evidence/search-${width}-${theme}.png`,fullPage:true});
  await page.goto('http://rag-v1-web:8000/conversations/'+demo.conversation);
  // Only first browser sends the demo, others inspect persisted real history.
  if(width===1440&&theme==='light') {
   await page.locator('textarea[name=body]').fill('Como devo investigar um site lento?');await page.getByRole('button',{name:'Enviar',exact:true}).click();
   await page.getByText('Fontes da resposta',{exact:true}).waitFor();
  }
  await page.locator('.conversation-message details').evaluateAll(nodes=>nodes.forEach(n=>n.open=true));await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
  if(!(await page.locator('main').innerText()).includes('nenhuma sondagem foi executada')) throw Error('Missing grounded answer');
  await page.screenshot({path:`/evidence/conversation-${width}-${theme}.png`,fullPage:true});
  await page.goto('http://rag-v1-web:8000/knowledge/sources/'+demo.source);await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
  if(!(await page.locator('main').innerText()).includes('Testes iniciais')) throw Error('Missing source');
  await page.screenshot({path:`/evidence/source-${width}-${theme}.png`,fullPage:true});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);if(overflow||errors.length)throw Error(JSON.stringify({overflow,errors}));
  results.push({width,theme,search:true,answer:true,source:true,overflow,errors});await context.close();
 }
 const c=await browser.newContext();const p=await c.newPage();await p.goto('http://rag-v1-web:8000/login');await p.locator('input[name=email]').fill('admin-a@rag.test');await p.locator('input[name=password]').fill(password);await p.getByRole('button',{name:'Entrar',exact:true}).click();await p.waitForURL('**/dashboard');
 await p.goto('http://rag-v1-web:8000/conversations/'+demo.conversation);await p.locator('textarea[name=body]').fill('Como devo investigar astronomia galáxia?');await p.getByRole('button',{name:'Enviar',exact:true}).click();await p.getByText('Não encontrei informações suficientes na Knowledge disponível para responder com segurança a essa pergunta.',{exact:true}).waitFor();await p.screenshot({path:'/evidence/no-match.png',fullPage:true});
 await c.close();
 const b=await browser.newContext();const pb=await b.newPage();await pb.goto('http://rag-v1-web:8000/login');await pb.locator('input[name=email]').fill('admin-b@rag.test');await pb.locator('input[name=password]').fill(password);await pb.getByRole('button',{name:'Entrar',exact:true}).click();await pb.waitForURL('**/dashboard');
 const cross=await pb.goto('http://rag-v1-web:8000/knowledge/sources/'+demo.source);if(cross.status()!==404)throw Error('Cross tenant source accessible');
 const conv=await pb.goto('http://rag-v1-web:8000/conversations/'+demo.conversation);if(conv.status()!==404)throw Error('Cross tenant conversation accessible');
 await pb.goto('http://rag-v1-web:8000/knowledge/rag');if((await pb.locator('main').innerText()).includes('Procedimento NOC'))throw Error('Cross tenant document leaked');await pb.screenshot({path:'/evidence/tenant-b-empty.png',fullPage:true});
 // Actual CSRF middleware, without a token.
 const csrf=await b.request.post('http://rag-v1-web:8000/knowledge/rag/reindex',{data:{}});if(csrf.status()!==419)throw Error('CSRF protection not active: '+csrf.status());
 fs.writeFileSync('/evidence/browser-results.json',JSON.stringify({externalProvider:'deterministic transport fixture, not live inference',results,noMatch:true,crossTenantSource:cross.status(),crossTenantConversation:conv.status(),csrf:csrf.status()},null,2));
 await browser.close();console.log('BROWSER PASS: desktop/mobile, light/dark, sources, no match, tenant isolation, CSRF');
})().catch(e=>{console.error(e);process.exit(1)});
