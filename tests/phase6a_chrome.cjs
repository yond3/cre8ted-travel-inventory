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


  const admin=await pageForUser('admin','admin123');
  await admin.waitForFunction(()=>typeof window.openUserModal==='function');
  await admin.evaluate(()=>window.openUserModal());
  await admin.waitForSelector('#userAccountKind');
  assert.equal(await admin.$eval('#userAccountKind',el=>el.value),'named');
  const username='chrome_six_'+Date.now();
  await admin.type('#userUsername',username);await admin.type('#userName','Chrome Employee');await admin.type('#userPassword','ChromePassword123');
  await admin.$eval('#userForm',form=>form.requestSubmit());
  await admin.waitForFunction(()=>!document.querySelector('#userModalOverlay').classList.contains('show'));
  const created=await admin.evaluate(async(username)=>{const r=await fetch('api/users.php');return(await r.json()).find(u=>u.username===username);},username);
  assert(created&&created.account_kind==='named');
  console.log('PASS Chrome named-account creation through existing user form');
  const department=await pageForUser('named_department','DepartmentNew123');
  assert(await department.$('a[href="account-security.html"]'));
  await department.goto(base+'/account-security.html',{waitUntil:'networkidle0'});
  await department.waitForFunction(()=>!document.querySelector('#passwordForm button').disabled);
  await department.type('[name=current]','DepartmentNew123');await department.type('[name=next]','ChromeDepartment123');await department.type('[name=confirm]','ChromeDepartment123');
  await Promise.all([department.waitForNavigation({waitUntil:'networkidle0'}),department.$eval('#passwordForm',form=>form.requestSubmit())]);
  assert(new URL(department.url()).pathname==='/login.html');
  await department.type('#username','named_department');await department.type('#password','ChromeDepartment123');
  await Promise.all([department.waitForNavigation({waitUntil:'networkidle0'}),department.$eval('#loginForm',form=>form.requestSubmit())]);
  assert(new URL(department.url()).pathname==='/department-api.html');
  assert.equal(await department.evaluate(async()=>{return(await fetch('api/users.php')).status;}),403);
  console.log('PASS Chrome Department password change, forced re-login and preserved role boundaries');
  assert.equal(errors.length,0,errors.join('\n'));console.log('PASS Chrome no unexpected page errors');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
