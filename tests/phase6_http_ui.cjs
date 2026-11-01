// Executes the real login script with a minimal DOM and controlled HTTP replies.
// These are behavior tests; browser rendering is a separate manual/Chrome check.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const script=fs.readFileSync(require('node:path').join(__dirname,'../php/security-ui.js'),'utf8');
const html=fs.readFileSync(require('node:path').join(__dirname,'../php/login.html'),'utf8');
async function scenario(label,policy,expected){
 const nodes=new Map(),controls=[];
 for(const match of html.matchAll(/<([a-z]+)\b([^>]*)>/gi)){
  const id=/\bid="([^"]+)"/.exec(match[2]);
  const node={disabled:/\bdisabled\b/.test(match[2]),hidden:/\bhidden\b/.test(match[2]),textContent:'',value:'',required:false};
  if(id)nodes.set(id[1],node);
  if(['input','button','select'].includes(match[1]))controls.push(node);
 }
 const calls=[];let release;
 const waiting=new Promise(resolve=>release=resolve);
 const fetch=async(url,options)=>{calls.push({url,options});if(url.includes('transport=1')){await waiting;if(policy instanceof Error)throw policy;return {ok:policy.ok,json:async()=>policy.body};}return {ok:true,json:async()=>({authenticated:false})};};
 const result=vm.runInNewContext(script,{document:{body:{dataset:{securityPage:'login'}},getElementById:id=>nodes.get(id),querySelectorAll:()=>controls},location:{protocol:'http:',hostname:'inventory.example.test'},fetch,addEventListener:()=>{},console});
 assert(controls.every(node=>node.disabled));assert.equal(nodes.get('loginForm').onsubmit,undefined);
 assert.equal(calls.length,1);assert(!JSON.stringify(calls).includes('password'));
 release();await result;
 if(expected){
  assert.equal(nodes.get('username').disabled,false);assert.equal(nodes.get('recoveryCode').disabled,true);
  assert.equal(typeof nodes.get('loginForm').onsubmit,'function');assert.equal(calls.length,2);assert.equal(nodes.get('loginError').textContent,'');
 }else{
  assert(controls.every(node=>node.disabled));assert.equal(nodes.get('loginForm').onsubmit,undefined);assert.equal(calls.length,1);assert(nodes.get('loginError').textContent.length>0);
 }
 console.log('PASS HTTP UI '+label);
}
(async()=>{
 await scenario('public HTTP enabled by server restores controls and login handlers',{ok:true,body:{transport_allowed:true}},true);
 await scenario('server denial keeps controls locked',{ok:false,body:{error:'Secure HTTPS is required'}},false);
 await scenario('missing policy does not silently enable credentials',{ok:true,body:{authenticated:false}},false);
 await scenario('network failure keeps controls locked',new Error('Network unavailable'),false);
 await scenario('invalid JSON keeps controls locked',{ok:true,get body(){throw Error('Invalid JSON');}},false);
})().catch(error=>{console.error(error);process.exitCode=1;});
