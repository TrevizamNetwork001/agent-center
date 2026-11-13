// Native HTTP QA of the immutable candidate on an isolated network, using fixture accounts only.
const {chromium}=require('/opt/browser/node_modules/playwright');
const fs=require('node:fs');
const {randomUUID}=require('node:crypto');
const assert=require('node:assert/strict');
(async()=>{
 const account=JSON.parse(fs.readFileSync('/qa/accounts.json'));
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const base='http://device-admin-publish-web:8000', results=[];
 async function login(page,role){await page.goto(base+'/login');await page.locator('[name=email]').fill(account.accounts[role]);await page.locator('[name=password]').fill(account.password);await page.getByRole('button',{name:'Entrar',exact:true}).click();await page.waitForURL('**/dashboard');}
 try{
  for(const width of [390,1440])for(const theme of ['light','dark']){
   const context=await browser.newContext({viewport:{width,height:1000},colorScheme:theme,reducedMotion:'reduce'});
   const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page,'admin');
   for(const [device,id] of Object.entries(account.devices)){
    await page.goto(base+'/noc-inventory/'+id);assert(await page.locator('#device-readonly-admin').isVisible());
    assert((await page.locator('#device-readonly-admin').innerText()).includes('CONGELADO'));
    assert.equal(await page.locator('form[action$="/readonly-lab"]').count(),0);
    const fixture=device==='ne8000'?'da-ne8000-bgp-idle':'da-ros7-memory-high';
    const form=page.locator('form').filter({has:page.locator('option[value="'+fixture+'"]')});
    await form.locator('xpath=ancestor::details[1]').locator('summary').first().click();
    await form.locator('select[name=fixture]').selectOption(fixture);await form.getByRole('button',{name:'Gerar demonstração SIMULADA'}).click();await page.waitForLoadState();
    assert((await page.locator('body').innerText()).includes('SIMULATED / LAB'));
    const token=await page.locator('input[name=_token]').first().inputValue();
    const denied=await page.request.post(base+'/noc-inventory/'+id+'/readonly-lab',{form:{_token:token,operation:'DEVICE_BGP_PEERS_READ'}});assert.equal(denied.status(),404);
    const invalid=await page.request.post(base+'/noc-inventory/'+id+'/analysis-lab',{headers:{Accept:'application/json'},form:{_token:token,fixture:'ssh-DEVICE_BGP_PEERS_READ',request_key:randomUUID(),device_revision:'1'}});assert.equal(invalid.status(),422);
    await page.locator('#device-readonly-admin').evaluate(el=>el.scrollIntoView({block:'start'}));
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await page.screenshot({path:`/evidence/admin-${device}-${width}-${theme}.png`});
    results.push({device,width,theme,native_demo:true,transport_route_absent:true,ssh_fixture_rejected:true});
   }
   await page.clock.install();await page.goto(base+'/noc-inventory/create');
   for(const [name,value]of Object.entries({name:'Formulário QA',hostname:'invalid hostname',management_ip:'192.0.2.200',model:'NE8000',network_role:'LAB',pop:'LAB'}))await page.locator('[name='+name+']').fill(value);
   await page.locator('[name=manufacturer]').selectOption('huawei');await page.locator('[name=platform]').selectOption('huawei-vrp');await page.getByRole('button',{name:'Salvar cadastro administrativo'}).click();
   assert.equal(await page.locator('[name=management_ip]').inputValue(),'192.0.2.200');assert.equal(await page.locator('[name=name]').inputValue(),'Formulário QA');assert(await page.locator('#noc-hostname-error').isVisible());
   await page.screenshot({path:`/evidence/admin-form-${width}-${theme}.png`});
   await page.mouse.move(-10,-10);await page.clock.fastForward(11000);assert.equal(await page.locator('#main>.feedback.error').isVisible(),false);assert(await page.locator('#noc-hostname-error').isVisible());assert.deepEqual(errors,[]);
   results.push({form:true,width,theme,preserved:true,localized:true,auto_dismiss:true});await context.close();
  }
  for(const role of ['other','viewer']){
   const context=await browser.newContext();const page=await context.newPage();await login(page,role);
   const id=account.devices.ne8000;
   if(role==='other'){const response=await page.goto(base+'/noc-inventory/'+id);assert.equal(response.status(),404);}
   else {await page.goto(base+'/noc-inventory/'+id);const token=await page.locator('input[name=_token]').first().inputValue();const response=await page.request.post(base+'/noc-inventory/'+id+'/analysis-lab',{form:{_token:token,fixture:'da-ne8000-bgp-idle',request_key:randomUUID(),device_revision:'1'}});assert.equal(response.status(),403);}
   await context.close();
  }
  fs.writeFileSync('/evidence/browser.json',JSON.stringify({passed:true,cases:results,two_tenants:true,rbac:true,source:'immutable candidate image, native HTTP/session/CSRF, isolated fixtures; no application source mount'},null,2)+'\n');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exit(1);});
