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
  await manager.waitForFunction(()=>document.querySelector('#inventoryTableCategorySelect')?.options.length>2);
  const before=await manager.evaluate(async()=>{const r=await fetch('api/inventory.php?item=ballpointpens');return r.json();});
  const name='Chrome category '+Date.now();
  await manager.evaluate(()=>window.openCategoryManager());
  await manager.$eval('#categoryName',(el,value)=>el.value=value,name);
  await manager.$eval('#categoryForm',form=>form.requestSubmit());
  await manager.waitForFunction(name=>document.querySelector('#categoryEdit')?.selectedOptions[0]?.textContent===name,{},name);
  const category=await manager.$eval('#categoryEdit',el=>el.value);
  await manager.evaluate(()=>window.closeModal('categoryModalOverlay'));
  await manager.evaluate(()=>window.openEditItemModal('ballpointpens'));
  await manager.select('#itmCategory',category);
  const invalidFields=await manager.$eval('#itemForm',form=>[...form.elements].filter(el=>el.willValidate&&!el.checkValidity()).map(el=>el.id));
  assert.deepEqual(invalidFields,[],'Existing fractional consumable values must remain valid in the category form');
  await manager.$eval('#itemForm',form=>form.requestSubmit());
  await manager.waitForFunction(()=>!document.querySelector('#itemModalOverlay').classList.contains('show'));
  const after=await manager.evaluate(async()=>{const r=await fetch('api/inventory.php?item=ballpointpens');return r.json();});
  assert.equal(after.category_id,Number(category));assert.equal(after.current_qty,before.current_qty);
  await manager.select('#inventoryTableCategorySelect',category);
  assert((await manager.$eval('#inventoryTableBody',el=>el.textContent)).includes('Ballpoint'));
  console.log('PASS Chrome Manager: category creation, existing item form assignment, catalog filtering and unchanged quantity');

  const staff=await pageForUser('juan','staff123');
  await staff.waitForFunction(()=>document.querySelector('#inventoryTableCategorySelect')?.options.length>2);
  assert(await staff.$eval('#manageCategoriesBtn',el=>el.classList.contains('hidden')));
  await staff.select('#inventoryTableCategorySelect',category);
  assert((await staff.$eval('#inventoryTableBody',el=>el.textContent)).includes('Ballpoint'));
  console.log('PASS Chrome Staff: category filtering and hidden administration');

  const department=await pageForUser('fleet_dept','staff123');
  assert(department.url().includes('department-api.html'));
  await department.$eval('#openStockBtn',el=>el.click());
  await department.waitForFunction(category=>[...document.querySelector('#stockCategory').options].some(o=>o.value===category),{},category);
  await department.select('#stockCategory',category);
  assert(await department.$eval('#stockItem',el=>[...el.options].some(o=>o.value==='ballpointpens')));
  console.log('PASS Chrome Department: existing requestable picker shares category filtering');

  const vendor=await pageForUser(null,null);
  await vendor.goto(base+'/vendor-apply.html',{waitUntil:'networkidle0'});
  await vendor.waitForSelector('.quote-row[data-key="ballpointpens"]');
  await vendor.$eval('.quote-check[data-key="ballpointpens"]',el=>el.click());
  await vendor.$eval('.quote-price[data-key="ballpointpens"]',el=>el.value='123.45');
  await vendor.select('#quoteCategory','uncategorized');
  assert(await vendor.$eval('.quote-row[data-key="ballpointpens"]',el=>el.hidden));
  await vendor.select('#quoteCategory',category);
  assert(await vendor.$eval('.quote-check[data-key="ballpointpens"]',el=>el.checked));
  assert.equal(await vendor.$eval('.quote-price[data-key="ballpointpens"]',el=>el.value),'123.45');
  assert(await vendor.$eval('.quote-row[data-key="ballpointpens"]',el=>!el.hidden));
  console.log('PASS Chrome Vendor: category filtering retains selected offers and prices');
  assert.deepEqual(errors,[],'Unexpected browser JavaScript errors');
  console.log('PASS Chrome functional checks complete; external assets blocked, visual styling remains a manual acceptance check');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
