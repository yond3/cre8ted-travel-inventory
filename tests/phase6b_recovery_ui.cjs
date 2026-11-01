// Browser behavior checks using only a local mock challenge, without real accounts.
const assert=require('node:assert/strict');
const http=require('node:http');
const fs=require('node:fs');
const path=require('node:path');
const puppeteer=require('puppeteer');
(async()=>{
 const root=path.resolve(__dirname,'../php');let verifications=0,browser;
 const assets={'/login.html':['login.html','text/html'],'/security-ui.js':['security-ui.js','application/javascript'],'/assets/security.css':['assets/security.css','text/css'],'/assets/vendor/qrcode-generator-2.0.4.js':['assets/vendor/qrcode-generator-2.0.4.js','application/javascript'],'/assets/logo-icon.png':['assets/logo-icon.png','image/png']};
 const server=http.createServer((req,res)=>{
  const pathname=new URL(req.url,'http://localhost').pathname;
  res.setHeader('Cache-Control','no-store');
  if(pathname==='/api/auth.php'){
   if(new URL(req.url,'http://localhost').searchParams.get('transport')==='1'){res.setHeader('Content-Type','application/json');return res.end(JSON.stringify({transport_allowed:true}));}
   res.setHeader('Content-Type','application/json');
   if(req.method==='GET')return res.end(JSON.stringify({authenticated:false,csrf_token:'mock',challenge:{purpose:'login'}}));
   let raw='';req.on('data',data=>raw+=data);req.on('end',()=>{
    const body=JSON.parse(raw);assert.equal(body.action,'verify');assert.equal(body.recovery_code,'01234567-89abcdef-01234567-89abcdef');assert.equal(body.code,undefined);verifications++;
    res.end(JSON.stringify({status:'ok',user:{role:'staff'}}));
   });return;
  }
  if(pathname==='/index.html'){res.setHeader('Content-Type','text/html');return res.end('<p>Mock authenticated page</p>');}
  const asset=assets[pathname];if(!asset){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',asset[1]);res.end(fs.readFileSync(path.join(root,asset[0])));
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const base='http://127.0.0.1:'+server.address().port;
 try{
  browser=await require('./local_browser_runtime.cjs').launch(puppeteer);
  const page=await browser.newPage();await page.setRequestInterception(true);page.on('request',request=>request.url().startsWith(base+'/')||request.url().startsWith('data:')?request.continue():request.abort());
  await page.goto(base+'/login.html',{waitUntil:'networkidle0'});await page.waitForFunction(()=>!document.querySelector('#challenge').hidden);
  await page.type('#otp','12');await page.click('#recoveryToggle');
  assert.equal(await page.$eval('#otp',input=>input.disabled),true);assert.equal(await page.$eval('#recoveryCode',input=>input.disabled),false);
  assert.match(await page.$eval('#challengeHelp',p=>p.textContent),/four groups of eight/);
  console.log('PASS Chrome recovery mode explains full format and disables inactive OTP validation');
  await page.type('#recoveryCode','01234567');await page.$eval('#verifyForm',form=>form.requestSubmit());
  await page.waitForFunction(()=>document.querySelector('#loginError').textContent.includes('one complete recovery code'));assert.equal(verifications,0);
  console.log('PASS Chrome incomplete recovery group produces guidance without a verification attempt');
  await page.click('#recoveryToggle');assert.equal(await page.$eval('#recoveryCode',input=>input.disabled),true);assert.equal(await page.$eval('#otp',input=>input.disabled),false);
  await page.click('#recoveryToggle');await page.type('#recoveryCode','01234567-89abcdef-01234567-89abcdef');
  await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#verifyForm',form=>form.requestSubmit())]);
  assert.equal(verifications,1);assert.equal(new URL(page.url()).pathname,'/index.html');
  console.log('PASS Chrome complete recovery line submits once through the recovery path');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
