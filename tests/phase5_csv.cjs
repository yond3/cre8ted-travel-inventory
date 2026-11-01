const assert=require('node:assert/strict');
(async()=>{
 const input=new URL(process.argv[2]);assert.equal(input.hostname,'127.0.0.1');const base=input.origin;
 const marker=await(await fetch(base+'/api/phase1-instance.json')).json();assert(/^[a-f0-9]{32}$/.test(marker.test_instance),'Disposable preview required');
 const login=await fetch(base+'/api/auth.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'maria',password:'manager123'})});
 assert.equal(login.status,200);const auth=await login.json();const cookie=login.headers.getSetCookie().map(c=>c.split(';')[0]).join('; ');
 const catalog=await(await fetch(base+'/api/stock_requests.php?requestable=1',{headers:{Cookie:cookie}})).json();assert(catalog.length);
 const result=await fetch(base+'/api/stock_requests.php',{method:'POST',headers:{Cookie:cookie,'Content-Type':'application/json','X-CSRF-Token':auth.csrf_token,'X-Idempotency-Key':crypto.randomUUID()},body:JSON.stringify({department:'Tour Operations',purpose:'=1+1',items:[{item_key:catalog[0].item_key,qty:1}]})});
 assert.equal(result.status,200,await result.text());
 const csv=await(await fetch(base+'/api/reports.php?type=stock_requests&format=csv',{headers:{Cookie:cookie}})).text();
 assert(csv.includes("'=1+1"),'Untrusted purpose must be spreadsheet-safe');
 console.log('PASS CSV formula neutralization through real API');
})().catch(e=>{console.error(e);process.exitCode=1;});
