// Read-only actual local login: stylesheet and transport readiness regression.
const assert=require('node:assert/strict'),puppeteer=require('puppeteer');
(async()=>{
 const base=new URL(process.argv[2]);assert.equal(base.protocol,'http:');assert.equal(base.hostname,'127.0.0.1');
 const browser=await require('./local_browser_runtime.cjs').launch(puppeteer);
 try{
  const page=await browser.newPage(),errors=[];await page.setRequestInterception(true);
  page.on('request',r=>r.url().startsWith(base.origin+'/')||r.url().startsWith('data:')?r.continue():r.abort());
  page.on('pageerror',e=>errors.push(e.message));
  const css=page.waitForResponse(r=>new URL(r.url()).pathname==='/assets/security.css');
  await page.goto(base.origin+'/login.html',{waitUntil:'networkidle0'});
  assert.equal((await css).status(),200);assert.match((await css).headers()['content-type'],/text\/css/);
  await page.waitForFunction(()=>!document.querySelector('#signInButton').disabled);
  const state=await page.evaluate(()=>({width:getComputedStyle(document.querySelector('main')).maxWidth,hidden:getComputedStyle(document.querySelector('#challenge')).display}));
  assert.equal(state.width,'480px');assert.equal(state.hidden,'none');
  await page.click('#setupToggle');assert.equal(await page.$eval('#grantLabel',el=>el.hidden),false);
  assert.deepEqual(errors,[]);
  console.log('PASS real Chrome normal system: CSS loads/applies, hidden sections stay hidden, HTTP readiness unlocks login, setup toggle works, no JavaScript errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
