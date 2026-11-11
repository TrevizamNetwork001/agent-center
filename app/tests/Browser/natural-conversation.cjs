const {chromium}=require('/opt/browser/node_modules/playwright');
const fs=require('fs');
const origin='http://natural-conversation-web:8000';
const demo=JSON.parse(fs.readFileSync('/lab/demo.json'));
const password=fs.readFileSync('/lab/password','utf8').trim();
const captureOnly=process.env.NATURAL_CAPTURE_ONLY==='1';
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const errors=[];
 async function login(width=1440,theme='light',email='admin-a@natural.test'){
  const context=await browser.newContext({viewport:{width,height:1000},colorScheme:theme});
  const page=await context.newPage();page.setDefaultTimeout(12000);page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/login');await page.locator('[name=email]').fill(email);await page.locator('[name=password]').fill(password);
  await page.getByRole('button',{name:'Entrar',exact:true}).click();await page.waitForURL('**/dashboard');
  return {context,page};
 }
 async function say(page,body){
  const count=await page.locator('.conversation-message').count();
  const requestKey=await page.locator('[name=request_key]').inputValue();
  await page.locator('[name=body]').fill(body);await page.getByRole('button',{name:'Enviar',exact:true}).click();
  await page.waitForFunction(n=>document.querySelectorAll('.conversation-message').length>=n+2,count);
  await page.waitForFunction(()=>!document.querySelector('[data-conversation-form]').matches('[aria-busy=true]'));
  return requestKey;
 }
 const {context,page}=await login();
 let duplicate=null,conflict=null;
 if(!captureOnly){
  await page.goto(origin+'/conversations/'+demo.social);
  for(const body of ['Boa tarde, meu consagrado!','E aí, meu rei, tudo certo?','Salve, calabreso!','Fala, meu parceiro!','Conta uma piada leve de plantão','Prefiro um tom formal.','Boa tarde, por gentileza.'])await say(page,body);
  await page.goto(origin+'/conversations/'+demo.technical);
  await say(page,'Rapaz, a rede tá uma carroça hoje kkkk');await say(page,'Meu BNG está apresentando perda de pacotes.');
  if(!(await page.locator('main').innerText()).includes('Ainda não há evidências suficientes'))throw Error('Incident evidence boundary missing');
  await page.goto(origin+'/conversations/'+demo.confirmation);
  await say(page,'Outro diagnóstico para google.com');await say(page,'Fala, meu parceiro!');
  const body='Sim, confirmo o diagnóstico de google.com.';const requestKey=await say(page,body);
  const before=await page.locator('.conversation-message').count();const csrf=await page.locator('[data-conversation-form] [name=_token]').inputValue();
  duplicate=await page.request.post(origin+'/conversations/'+demo.confirmation+'/messages',{headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},form:{request_key:requestKey,body}});
  conflict=await page.request.post(origin+'/conversations/'+demo.confirmation+'/messages',{headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},form:{request_key:requestKey,body:'Cancela'}});
  if(duplicate.status()!==200||conflict.status()!==409)throw Error('Idempotency failed');
  await page.reload();if(await page.locator('.conversation-message').count()!==before)throw Error('Refresh duplicated history');
  if(!(await page.locator('main').innerText()).includes('Confirmação processada'))throw Error('Confirmation did not advance');
  await page.goto(origin+'/conversations/'+demo.failure);await say(page,'Conta uma piada com falha do modelo');
  if(!(await page.locator('main').innerText()).includes('Resposta local'))throw Error('Fallback not visible');
  await page.goto(origin+'/conversations/'+demo.knowledge);await say(page,'O que a Knowledge diz sobre lentidão?');
  if(!(await page.locator('main').innerText()).includes('Fontes da resposta'))throw Error('Knowledge sources missing');
  await say(page,'O que a Knowledge diz sobre galaxiazxq977?');
  if(!(await page.locator('main').innerText()).includes('Não encontrei informações suficientes'))throw Error('Missing evidence response');
 }
 await context.close();
 const results=[];
 for(const width of [1440,768,390])for(const theme of ['light','dark']){
  const {context,page}=await login(width,theme);
  for(const key of ['social','technical','confirmation','failure','knowledge']){
   const response=await page.goto(origin+'/conversations/'+demo[key]);if(response.status()!==200)throw Error('Conversation page '+response.status());
   if(captureOnly&&key==='confirmation')await page.getByText(/Verificações bloqueadas pela política do Agent/).waitFor();
   await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);if(overflow)throw Error('Horizontal overflow');
   if(width!==768||key==='social')await page.screenshot({path:`/evidence/${key}-${width}-${theme}.png`,fullPage:true});
   results.push({key,width,theme,status:response.status(),overflow});
  }
  await context.close();
 }
 const other=await login(390,'light','admin-b@natural.test');
 const cross=await other.page.goto(origin+'/conversations/'+demo.social);if(cross.status()!==404)throw Error('Tenant leak');
 const csrf=await other.context.request.post(origin+'/conversations',{form:{request_key:'00000000-0000-4000-8000-000000000000'}});if(csrf.status()!==419)throw Error('CSRF bypass');
 await other.context.close();
 if(errors.length)throw Error('Browser JS error: '+errors.join(','));
 fs.writeFileSync('/evidence/browser-results'+(captureOnly?'-final':'')+'.json',JSON.stringify({status:'PASS',inference:'deterministic guarded LAB transport fixture; real OpenAI evidence is separate',captureOnly,results,duplicate:duplicate?.status()??null,conflictingRetry:conflict?.status()??null,crossTenant:cross.status(),csrf:csrf.status(),errors},null,2));
 await browser.close();console.log('BROWSER PASS: natural messages, confirmation, fallback, Knowledge, desktop/mobile/tablet, themes, tenant isolation and CSRF');
})().catch(e=>{console.error(e.message);process.exit(1)});
