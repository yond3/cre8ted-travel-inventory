const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
for(const name of ['index.html','department-api.html','account-security.html','login.html']){
 const source=fs.readFileSync('php/'+name,'utf8');
 for(const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi))if(match[1].trim())new vm.Script(match[1],{filename:name});
 const ids=[...source.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);assert.equal(ids.length,new Set(ids).size,'Duplicate HTML ID');
 console.log('PASS inline scripts and unique IDs: '+name);
}
new vm.Script(fs.readFileSync('php/stock-request-ui.js','utf8'));
console.log('PASS shared request UI syntax');

new vm.Script(fs.readFileSync('php/security-ui.js','utf8'));
console.log('PASS shared security UI syntax');
