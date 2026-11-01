'use strict';
(async()=>{
 // Keep forms inactive until the server confirms the same policy it enforces.
 const controls=[...document.querySelectorAll('input,button,select')];
 const disabledBefore=controls.map(el=>el.disabled);controls.forEach(el=>el.disabled=true);
 try{
   const response=await fetch('api/auth.php?transport=1',{credentials:'include',cache:'no-store'});
   const policy=await response.json();
   if(!response.ok||policy.transport_allowed!==true)throw Error(policy.error||'Authentication is unavailable for this address.');
   controls.forEach((el,index)=>el.disabled=disabledBefore[index]);
 }catch(error){const warning=document.getElementById('loginError')||document.getElementById('message');warning.textContent=error.message;return;}
 const $=id=>document.getElementById(id),login=document.body.dataset.securityPage==='login';let csrf='',busy=false,setup=false,purpose='login',recovery=false;
 const message=login?$('loginError'):$('message');
 function clearSecrets(){for(const id of ['manualSecret','handoverToken'])if($(id))$(id).textContent='';if($('recoveryCodes'))$('recoveryCodes').value='';if($('qr'))$('qr').replaceChildren();}
 addEventListener('pagehide',clearSecrets);addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
 async function send(path,body){const r=await fetch('api/'+path,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json',...(csrf?{'X-CSRF-Token':csrf}:{})},body:JSON.stringify(body)});let data;try{data=await r.json();}catch{throw Error('Service unavailable; try again.');}if(!r.ok)throw Error(data.error||'Action failed');if(data.csrf_token)csrf=data.csrf_token;return data;}
 async function perform(fn){if(busy)return;busy=true;message.textContent='';try{await fn();}catch(error){message.textContent=error.message;}finally{busy=false;}}
 function setRecoveryMode(active){
   recovery=active;$('codeLabel').hidden=active;$('otp').required=!active;$('otp').disabled=active;
   $('recoveryLabel').hidden=!active;$('recoveryCode').required=active;$('recoveryCode').disabled=!active;
   $('otp').value='';$('recoveryCode').value='';$('recoveryToggle').textContent=active?'Use authenticator code':'Use a recovery code';
   $('challengeHelp').textContent=active?'Enter ONE complete unused recovery code from your latest saved set: four groups of eight characters. Copy the whole line, including its hyphens.':'Enter the current code from your authenticator app. Codes change every 30 seconds.';
 }
 function showChallenge(data){purpose=data.challenge.purpose;$('credentials').hidden=true;$('challenge').hidden=false;$('recoveryToggle').hidden=purpose==='enroll';$('challengeTitle').textContent=purpose==='enroll'?'Confirm authenticator setup':'Authenticator verification';
   setRecoveryMode(false);$('setupSecret').hidden=!data.secret;
   if(data.secret){$('manualSecret').textContent=data.secret;const qr=qrcode(0,'M');qr.addData(data.uri);qr.make();const img=document.createElement('img');img.src=qr.createDataURL(5,12);img.alt='Authenticator setup QR code';$('qr').replaceChildren(img);}
 }
 function finishLogin(data){if(data.status==='ok'){clearSecrets();location.href=data.user.role==='department'?'department-api.html':'index.html';}else if(data.status==='enrolled'){$('challenge').hidden=true;$('completed').hidden=false;$('setupSecret').hidden=true;$('manualSecret').textContent='';$('qr').replaceChildren();$('recoveryCodes').value=data.recovery_codes.join('\n');}else showChallenge(data);}
 if(login){
   $('setupToggle').onclick=()=>{setup=!setup;$('grantLabel').hidden=!setup;$('grant').required=setup;$('signInButton').textContent=setup?'Start authorized setup':'Sign in';};
   $('loginForm').onsubmit=e=>{e.preventDefault();perform(async()=>{const data=await send('auth.php',{action:setup?'enroll_begin':'login',username:$('username').value,password:$('password').value,...(setup?{grant:$('grant').value.trim()}: {})});$('password').value='';$('grant').value='';finishLogin(data);});};
   $('verifyForm').onsubmit=e=>{e.preventDefault();perform(async()=>{const recoveryValue=$('recoveryCode').value.trim();if(recovery&&!/^[0-9a-f]{32}$/i.test(recoveryValue.replace(/-/g,'')))throw Error('Enter one complete recovery code: four groups of eight characters. Copy one full line from your latest saved set.');const data=await send('auth.php',{action:'verify',...(recovery?{recovery_code:recoveryValue}:{code:$('otp').value})});$('otp').value='';$('recoveryCode').value='';finishLogin(data);});};
   $('recoveryToggle').onclick=()=>setRecoveryMode(!recovery);
   $('cancelLogin').onclick=()=>perform(async()=>{await send('auth.php',{action:'cancel'});clearSecrets();location.reload();});
 }else{
   $('passwordForm').onsubmit=e=>{e.preventDefault();perform(async()=>{const f=$('passwordForm');if(f.elements.next.value!==f.elements.confirm.value)throw Error('New passwords do not match.');const r=await fetch('api/users.php?id=me',{method:'PUT',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'change_password',current_password:f.elements.current.value,new_password:f.elements.next.value})});const data=await r.json();if(!r.ok)throw Error(data.error||'Password change failed');f.reset();location.replace('login.html');});};
   $('securityAction').elements.action.onchange=()=>{const action=$('securityAction').elements.action.value;$('adminGrantFields').hidden=action!=='grant';$('securityAction').elements.reason.required=action!=='recovery_codes';};
   $('securityAction').onsubmit=e=>{e.preventDefault();perform(async()=>{const f=$('securityAction'),body=Object.fromEntries(new FormData(f));if(body.action==='grant'&&!confirm('Confirm you have verified the employee identity. Lost-device recovery immediately revokes their existing authenticator, sessions and recovery codes.'))return;const result=await send('mfa.php',body);f.elements.password.value='';f.elements.code.value='';if(result.grant){$('handoverToken').textContent=result.grant;$('handover').hidden=false;}if(result.recovery_codes){$('securityAction').hidden=true;$('newRecovery').hidden=false;$('recoveryCodes').value=result.recovery_codes.join('\n');}});};
 }
 try{const r=await fetch('api/auth.php',{credentials:'include',cache:'no-store'}),data=await r.json();if(!r.ok)throw Error(data.error||'Authentication unavailable');csrf=data.csrf_token||'';
   if(login){if(data.authenticated){location.replace(data.user.role==='department'?'department-api.html':'index.html');return;}if(data.challenge)showChallenge(data);}
   else{if(!data.authenticated){location.replace('login.html');return;}$('identity').textContent=data.user.name+' ('+data.user.username+')';$('back').href=data.user.role==='department'?'department-api.html':'index.html';$('passwordForm').querySelector('button').disabled=false;
     const mr=await fetch('api/mfa.php',{credentials:'include',cache:'no-store'}),md=await mr.json();if(mr.ok){$('mfaControls').hidden=false;$('enrollmentStatus').textContent=md.enrolled?'Authenticator enrolled. Keep recovery codes separate from your phone.':'Not enrolled. Ask an enrolled administrator for verified setup authorization.';if(md.users){$('adminGrantOption').hidden=false;for(const user of md.users){if(!user.active||user.account_kind!=='named')continue;const option=document.createElement('option');option.value=user.id;option.textContent=user.name+' ('+user.username+')'+(user.confirmed_at&&!user.revoked_at?' - enrolled':' - not enrolled');$('mfaUser').append(option);}}}
   }
 }catch(error){message.textContent=error.message;}
})();
