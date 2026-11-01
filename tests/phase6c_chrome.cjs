// Phase 6C UI regression, strictly marked disposable fixture only.
const assert=require('node:assert/strict'),puppeteer=require('puppeteer');
(async()=>{
 const url=new URL(process.argv[2]);assert.equal(url.hostname,'127.0.0.1');const base=url.origin;
 const marker=await(await fetch(base+'/api/phase1-instance.json')).json();assert(/^[a-f0-9]{32}$/.test(marker.test_instance));
 const browser=await require('./local_browser_runtime.cjs').launch(puppeteer);
 try{
  const page=await browser.newPage();await page.setRequestInterception(true);
  page.on('request',r=>{
   if(r.url().startsWith(base+'/')||r.url().startsWith('data:'))return r.continue();
   if(r.url().replace(/\/$/,'')==='https://cdn.tailwindcss.com')return r.respond({contentType:'application/javascript',body:'window.tailwind={config:{}};'});
   return r.abort();
  });
  await page.goto(base+'/login.html',{waitUntil:'networkidle0'});await page.type('#username','admin');await page.type('#password','admin123');
  await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#loginForm',f=>f.requestSubmit())]);
  await page.evaluate(()=>document.querySelector('#navUsersItem').click());
  await page.waitForFunction(()=>document.querySelector('#usersTableBody').textContent.includes('fleet_dept'));
  const before=await page.evaluate(async()=> (await(await fetch('api/users.php')).json()).find(u=>u.username==='fleet_dept'));
  assert(before.active&&before.account_kind==='unclassified');
  await page.evaluate(id=>window.openUserModal(id),before.id);
  assert.equal(await page.$eval('#userAccountKind',e=>e.value),before.account_kind);
  assert.equal(await page.$eval('#userAccountKind option[value=shared]',e=>e.disabled),true);
  await page.$eval('#userForm',f=>f.requestSubmit());await page.waitForFunction(()=>!document.querySelector('#userModalOverlay').classList.contains('show'));
  console.log('PASS Chrome legacy account can be saved without reclassification; shared choice remains disabled.');
  const response=page.waitForResponse(r=>r.url().includes('/api/users.php?id=')&&r.request().method()==='PUT');
  await page.evaluate(id=>{void window.toggleUserActive(id,false);},before.id);
  await page.waitForFunction(()=>document.querySelector('#confirmModalOverlay').classList.contains('show'));await page.click('#confirmModalOkBtn');assert.equal((await response).status(),200);
  const after=await page.evaluate(async()=> (await(await fetch('api/users.php')).json()).find(u=>u.username==='fleet_dept'));
  for(const field of ['id','username','department','account_kind'])assert.equal(after[field],before[field]);assert.equal(after.active,false);
  console.log('PASS Chrome legacy deactivation preserves ID, username, department and classification.');
  const denied=page.waitForResponse(r=>r.url().includes('/api/users.php?id=')&&r.request().method()==='PUT');
  await page.evaluate(id=>{void window.toggleUserActive(id,true);},before.id);
  await page.waitForFunction(()=>document.querySelector('#confirmModalOverlay').classList.contains('show'));await page.click('#confirmModalOkBtn');
  const rejection=await denied;assert.equal(rejection.status(),409);assert.match((await rejection.json()).error,/individual account owner/);
  const final=await page.evaluate(async()=> (await(await fetch('api/users.php')).json()).find(u=>u.username==='fleet_dept'));assert.equal(final.active,false);
  console.log('PASS Chrome legacy reactivation without named ownership rejected and remains inactive.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
