// Real Chrome functional checks against a marked DISPOSABLE preview only.
// Uses the already-installed Puppeteer; does not install app dependencies.
const assert = require('node:assert/strict');
const http = require('node:http');
const puppeteer = require('puppeteer');

(async () => {
 const url = new URL(process.argv[2]);
 assert(url.protocol === 'http:' && url.hostname === '127.0.0.1' && url.port, 'A loopback preview URL is required');
 const base = url.origin;
 const marker = await new Promise((resolve,reject) => {
  http.get(base+'/api/phase1-instance.json', response => {
   assert.equal(response.statusCode,200,'Refusing browser writes to an unmarked application');
   let data='';response.on('data',c=>data+=c);response.on('end',()=>{try{resolve(JSON.parse(data));}catch(e){reject(e);}});
  }).on('error',reject);
 });
 assert(/^[a-f0-9]{32}$/.test(marker.test_instance),'Refusing browser writes outside the disposable runner');
 const browser = await require('./local_browser_runtime.cjs').launch(puppeteer);
 const errors=[];
 try {
  async function pageForUser(username,password){
   const context=await browser.createBrowserContext();
   const page=await context.newPage();
   await page.setRequestInterception(true);
   page.on('request',req=>{
    if(req.url().startsWith(base+'/') || req.url().startsWith('data:')) return req.continue();
    // No external CDN requests: presentation-only Tailwind config remains available.
    if(req.url()==='https://cdn.tailwindcss.com/') return req.respond({contentType:'application/javascript',body:'window.tailwind={config:{}};'});
    if(req.url()==='https://cdn.tailwindcss.com') return req.respond({contentType:'application/javascript',body:'window.tailwind={config:{}};'});
    return req.abort();
   });
   page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/login.html',{waitUntil:'networkidle0'});
   if(username){
    await page.type('#username',username);await page.type('#password',password);
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#loginForm',form=>form.requestSubmit())]);
   }
   return page;
  }

  const manager=await pageForUser('maria','manager123');
  await manager.waitForFunction(()=>typeof window.openStockRequestModal==='function'&&document.querySelector('#inventoryTableBody'));
  const token=await manager.evaluate(async()=>{return(await(await fetch('api/auth.php')).json()).csrf_token;});
  const fixture=await manager.evaluate(async(token)=>{
    const result=[];
    for(const n of ['Paper','Pens']){
      const response=await fetch('api/inventory.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token,'X-Idempotency-Key':crypto.randomUUID()},body:JSON.stringify({label:'Phase5 Chrome '+n+' '+Date.now(),unit:'pcs',item_type:'consumable',current_qty:20,min_qty:1,max_qty:100})});
      if(!response.ok)throw Error(JSON.stringify(await response.json()));
      result.push(await response.json());
    }return result;
  },token);
  const purpose='Chrome multi-item check '+Date.now();
  await manager.evaluate(()=>window.openStockRequestModal());
  await manager.waitForSelector('[data-request-form]');
  await manager.$eval('[data-request-form] [name=purpose]',(el,v)=>el.value=v,purpose);
  await manager.select('[data-request-form] [data-item]',fixture[0].item_key);
  await manager.$eval('[data-request-form] [data-qty]',el=>el.value='3');
  await manager.click('[data-request-form] [data-add]');
  await manager.$$eval('[data-request-form] [data-item]',(els,key)=>{els[1].value=key;els[1].dispatchEvent(new Event('change'));},fixture[1].item_key);
  await manager.$eval('[data-request-form]',form=>form.requestSubmit());
  await manager.waitForFunction(()=>!document.querySelector('[data-request-form]'));
  await manager.evaluate(()=>document.querySelector('[data-view="stock-requests"]').click());
  await manager.waitForFunction(purpose=>document.querySelector('#stockRequestsTableBody').textContent.includes(purpose),{},purpose);
  await manager.evaluate(purpose=>{
    const row=[...document.querySelectorAll('#stockRequestsTableBody tr')].find(r=>r.textContent.includes(purpose));
    [...row.querySelectorAll('button')].find(b=>b.textContent==='Review').click();
  },purpose);
  await manager.waitForSelector('[role=dialog] [data-qty]');
  await manager.$eval('[role=dialog] form',form=>form.requestSubmit());
  await manager.waitForFunction(()=>!document.querySelector('[role=dialog]'));
  await manager.waitForFunction(purpose=>[...document.querySelectorAll('#stockRequestsTableBody tr')].find(r=>r.textContent.includes(purpose))?.textContent.includes('Self-approved'),{},purpose);
  console.log('PASS Chrome multi-item request and manager self approval');
  const staff=await pageForUser('juan','staff123');
  await staff.waitForFunction(()=>document.querySelector('#inventoryTableBody'));
  await staff.evaluate(()=>document.querySelector('[data-view="stock-requests"]').click());
  await staff.waitForFunction(purpose=>document.querySelector('#stockRequestsTableBody').textContent.includes(purpose),{},purpose);
  await staff.evaluate(purpose=>{
    const row=[...document.querySelectorAll('#stockRequestsTableBody tr')].find(r=>r.textContent.includes(purpose));
    if([...row.querySelectorAll('button')].some(b=>b.textContent==='Review'))throw Error('Staff review action exposed');
    [...row.querySelectorAll('button')].find(b=>b.textContent==='Issue').click();
  },purpose);
  await staff.waitForSelector('[role=dialog] [data-qty]');
  await staff.$eval('[role=dialog] [data-qty]',el=>el.value='1');
  await staff.$eval('[role=dialog] form',form=>form.requestSubmit());
  await staff.waitForFunction(()=>!document.querySelector('[role=dialog]'));
  await staff.waitForFunction(purpose=>[...document.querySelectorAll('#stockRequestsTableBody tr')].find(r=>r.textContent.includes(purpose))?.textContent.includes('Partially Issued'),{},purpose);
  console.log('PASS Chrome staff partial issuance and hidden approval');
  const department=await pageForUser('fleet_dept','staff123');
  await department.waitForSelector('#openStockBtn');
  await department.click('#openStockBtn');
  await department.waitForSelector('[data-request-form]');
  const dp='Department Chrome request '+Date.now();
  await department.$eval('[data-request-form] [name=purpose]',(el,v)=>el.value=v,dp);
  await department.select('[data-request-form] [data-item]',fixture[0].item_key);
  await department.click('[data-request-form] [data-add]');
  await department.$$eval('[data-request-form] [data-item]',(els,key)=>els[1].value=key,fixture[1].item_key);
  await department.$eval('[data-request-form]',form=>form.requestSubmit());
  await department.waitForFunction(()=>!document.querySelector('[data-request-form]'));
  await department.waitForFunction(dp=>document.querySelector('#stockRequestsList').textContent.includes(dp),{},dp);
  console.log('PASS Chrome department multi-item submission');
  await manager.evaluate(()=>[...document.querySelectorAll('#stockRequestsPanel button')].find(b=>b.textContent==='Protected stock policy').click());
  await manager.waitForSelector('[role=dialog] [name=qty]');
  await manager.select('[role=dialog] [name=policy_item]',fixture[1].item_key);
  await manager.$eval('[role=dialog] [name=qty]',el=>el.value='5');
  await manager.$eval('[role=dialog] [name=reason]',el=>el.value='Browser-tested reserve policy');
  await manager.$eval('[role=dialog] form',form=>form.requestSubmit());
  await manager.waitForFunction(()=>!document.querySelector('[role=dialog]'));
  const policy=await manager.evaluate(async key=>(await(await fetch('api/inventory.php?item='+encodeURIComponent(key))).json()),fixture[1].item_key);
  assert.equal(Number(policy.protected_qty),5);
  console.log('PASS Chrome protected policy form');
  assert.deepEqual(errors,[],'Unexpected browser JavaScript errors');
  console.log('PASS Chrome no unexpected page errors (external CDN styling excluded)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
