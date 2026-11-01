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


  const crypto=require('node:crypto');
  function otp(secret,offset=0){const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits='';for(const c of secret)bits+=alphabet.indexOf(c).toString(2).padStart(5,'0');const key=Buffer.from((bits.match(/.{8}/g)||[]).map(v=>parseInt(v,2)));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)+offset));const h=crypto.createHmac('sha1',key).update(counter).digest();return String((h.readUInt32BE(h[h.length-1]&15)&0x7fffffff)%1000000).padStart(6,'0');}
  const page=await pageForUser();
  const handover=await page.evaluate(async()=>await(await fetch('api/phase6b-preview-handover.json')).json());
  const fixture=handover.browser;assert(fixture&&fixture.grant);
  async function startSetup(grant){await page.goto(base+'/login.html',{waitUntil:'networkidle0'});await page.click('#setupToggle');await page.type('#username',fixture.username);await page.type('#password',fixture.password);await page.type('#grant',grant);await page.$eval('#loginForm',f=>f.requestSubmit());await page.waitForFunction(()=>document.querySelector('#manualSecret').textContent.length>0);assert((await page.$eval('#qr img',img=>img.src)).startsWith('data:image/'));return page.$eval('#manualSecret',e=>e.textContent);}
  async function confirmSetup(secret){await page.type('#otp',otp(secret));await page.$eval('#verifyForm',f=>f.requestSubmit());await page.waitForFunction(()=>!document.querySelector('#completed').hidden);const codes=(await page.$eval('#recoveryCodes',e=>e.value)).split('\n');assert.equal(codes.length,10);assert.equal(await page.evaluate(async()=>{return(await(await fetch('api/auth.php')).json()).authenticated;}),false);return codes;}
  const secret=await startSetup(fixture.grant);const oldCodes=await confirmSetup(secret);
  console.log('PASS Chrome authorized enrollment, locally rendered QR and one-time recovery display');
  await page.goto(base+'/login.html',{waitUntil:'networkidle0'});await page.type('#username',fixture.username);await page.type('#password',fixture.password);await page.$eval('#loginForm',f=>f.requestSubmit());await page.waitForFunction(()=>!document.querySelector('#challenge').hidden);
  assert.equal(await page.evaluate(async()=>{return(await fetch('api/inventory.php')).status;}),401);
  await page.type('#otp',otp(secret,1));await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#verifyForm',f=>f.requestSubmit())]);assert(new URL(page.url()).pathname==='/index.html');
  console.log('PASS Chrome password-only access blocked and authenticator login succeeds');
  await page.goto(base+'/account-security.html',{waitUntil:'networkidle0'});await page.waitForFunction(()=>!document.querySelector('#mfaControls').hidden);await page.select('#securityAction [name=action]','replace');await page.type('#securityAction [name=password]',fixture.password);await page.type('#securityAction [name=reason]','Replace authenticator during browser regression test');await page.$eval('#securityAction',f=>f.requestSubmit());await page.waitForFunction(()=>document.querySelector('#handoverToken').textContent.length===64);const replacement=await page.$eval('#handoverToken',e=>e.textContent);assert.equal(await page.evaluate(async()=>{return(await(await fetch('api/auth.php')).json()).authenticated;}),false);
  const newSecret=await startSetup(replacement);assert.notEqual(newSecret,secret);const newCodes=await confirmSetup(newSecret);
  console.log('PASS Chrome working authenticator replacement signs out setup browser and confirms fresh enrollment');
  await page.goto(base+'/login.html',{waitUntil:'networkidle0'});await page.type('#username',fixture.username);await page.type('#password',fixture.password);await page.$eval('#loginForm',f=>f.requestSubmit());await page.waitForFunction(()=>!document.querySelector('#challenge').hidden);await page.click('#recoveryToggle');await page.type('#recoveryCode',oldCodes[0]);await page.$eval('#verifyForm',f=>f.requestSubmit());await page.waitForFunction(()=>document.querySelector('#loginError').textContent.length>0);await page.$eval('#recoveryCode',(e,v)=>e.value=v,newCodes[0]);await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#verifyForm',f=>f.requestSubmit())]);assert(new URL(page.url()).pathname==='/index.html');
  console.log('PASS Chrome old recovery codes rejected and current recovery login succeeds');
  const freshHandover=await page.evaluate(async(password)=>{const status=await(await fetch('api/mfa.php')).json();const user=status.users.find(u=>u.username==='preview_admin');const session=await(await fetch('api/auth.php')).json();const r=await fetch('api/mfa.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf_token},body:JSON.stringify({action:'grant',user_id:user.id,purpose:'initial',reason:'Verified disposable manual preview handover',password})});if(!r.ok)throw Error((await r.json()).error);return(await r.json()).grant;},fixture.password);
  require('node:fs').writeFileSync('PHASE6B_MANUAL_HANDOVER.json',JSON.stringify({preview_url:base+'/login.html',username:'preview_admin',password:'MfaPassword123',grant:freshHandover,expires_at:new Date(Date.now()+900000).toISOString(),notice:'Disposable test authorization only. Never copy to normal deployment.'},null,2));
  assert.equal(errors.length,0,errors.join('\n'));console.log('PASS Chrome no unexpected page or CSP errors');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
