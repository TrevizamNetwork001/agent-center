const {chromium}=require('/opt/browser/node_modules/playwright');
const fs=require('fs');
const assert=require('assert/strict');
const script=fs.readFileSync('/source/app/public/agent-center.js','utf8');
const target='https://agent-ui.test/agents/fixture';
const progress=state=>`<div data-agent-update-progress data-operation="one" data-state="${state}" data-percent="${state==='COMPLETED'?100:50}"><span data-update-fill></span></div>`;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  for(const [initial,next,tenant,expected] of [['RUNNING','COMPLETED','1',true],['COMPLETED','COMPLETED','1',true],['RUNNING','FAILED','1',false],['RUNNING','COMPLETED','2',false]]){
   const page=await browser.newPage();const errors=[];let returns=0;
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',route=>{
    const url=new URL(route.request().url());
    if(url.origin!=='https://agent-ui.test')return route.abort();
    if(url.searchParams.has('fragment'))return route.fulfill({headers:{'Content-Type':'text/html','X-AC-Fragment':'agent-detail','X-AC-Tenant':tenant},body:progress(next)});
    if(!url.searchParams.has('updating')){returns++;return route.fulfill({contentType:'text/html',body:'<h1>Agent pronto</h1>'});}
    return route.fulfill({contentType:'text/html',body:`<body><main><div id="agent-detail" data-live-panel data-tenant="1" data-interval="150" data-completion-url="${target}">${progress(initial)}</div></main><script>${script}</script></body>`});
   });
   await page.goto(target+'?updating=1');
   if(expected){await page.waitForURL(target);await page.getByRole('heading',{name:'Agent pronto'}).waitFor();await page.waitForTimeout(500);assert.equal(returns,1);}
   else{await page.waitForTimeout(2200);assert.equal(returns,0);assert.match(page.url(),/updating=1/);}
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('PASS: completion returns once, completed initial page returns, failure stays visible, tenant mismatch cannot redirect');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
