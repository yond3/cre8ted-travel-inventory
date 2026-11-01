/* Shared UI for the existing central/department request workflow. No separate request store. */
window.createStockRequestUI = function (adapter) {
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const manager = () => ['manager','super_admin'].includes(adapter.user()?.role);
  const central = () => !adapter.user()?.department || adapter.user()?.role !== 'department';
  const button = (label, action) => { const b = document.createElement('button'); b.type='button'; b.className='btn-secondary'; b.textContent=label; b.onclick=action; return b; };
  let modal;
  function close(){ modal?.remove(); modal=null; }
  function panel(title){
    close(); modal=document.createElement('div'); modal.className='fixed inset-0 bg-black/45 z-[2000] flex items-center justify-center p-4';
    modal.style.cssText='position:fixed;inset:0;background:#0007;z-index:2100;display:flex;align-items:center;justify-content:center;padding:16px';
    modal.innerHTML='<section role="dialog" aria-modal="true" class="bg-card rounded-card p-6 shadow-modal" style="background:white;border-radius:18px;padding:24px;max-width:1000px;width:100%;max-height:90vh;overflow:auto"><header class="flex justify-between gap-3"><h3 class="font-bold"></h3></header><div data-content></div><p data-error role="alert" style="color:#a02222"></p></section>';
    modal.querySelector('h3').textContent=title;modal.querySelector('header').append(button('Close',close));document.body.append(modal);
    return modal.querySelector('[data-content]');
  }
  function error(e){if(modal)modal.querySelector('[data-error]').textContent=e.error||e.message||'Could not save.';else adapter.error(e);}
  async function save(form,fn){
    if(form.dataset.busy)return;form.dataset.busy='1';const submit=form.querySelector('[type=submit]');if(submit)submit.disabled=true;
    try{await fn();close();await adapter.refresh();}catch(e){error(e);}finally{delete form.dataset.busy;if(submit)submit.disabled=false;}
  }
  async function openCreate(preset=null,existing=null){
    try{
      const catalog=await adapter.api('stock_requests.php?requestable=1');
      const content=panel(existing?'Edit pending request':'Request from stock');
      const user=adapter.user();const departments=await adapter.api('stock_requests.php?departments=1');
      content.innerHTML='<form data-request-form><label>Department<select name="department" class="w-full border rounded-xl p-2"></select></label><label>Purpose / justification<textarea name="purpose" required maxlength="1000" class="w-full border rounded-xl p-2"></textarea></label><label>Expected collection date (optional)<input type="date" name="expected_collection" class="border rounded-xl p-2"></label><p>Submitting records demand. Stock is reserved only after manager approval.</p><label>Filter items<input data-search placeholder="Search item or category" class="w-full border rounded-xl p-2"></label><select data-category class="w-full border rounded-xl p-2"><option value="">All categories</option><option value="uncategorized">Uncategorized</option></select><div data-lines></div><button type="button" data-add class="btn-secondary">Add item</button><button type="submit" class="btn-primary">Submit request</button></form>';
      const form=content.querySelector('form');const dept=form.elements.department;
      departments.forEach(d=>dept.add(new Option(d,d)));dept.value=existing?.department||departments[0];dept.disabled=departments.length===1;
      form.elements.purpose.value=existing?.purpose||'';form.elements.expected_collection.value=existing?.expected_collection||'';
      const categories=new Map();catalog.forEach(i=>{if(i.category_id!=null)categories.set(String(i.category_id),i.category_label);if(i.category_parent_id!=null)categories.set(String(i.category_parent_id),i.category_parent_name);});
      const category=form.querySelector('[data-category]');[...categories].sort((a,b)=>a[1].localeCompare(b[1])).forEach(([id,name])=>category.add(new Option(name,id)));
      const lineRoot=form.querySelector('[data-lines]');
      function options(select,preserve){
        const categoryValue=category.value,search=form.querySelector('[data-search]').value.toLowerCase();
        const filtered=catalog.filter(i=>(!categoryValue||(categoryValue==='uncategorized'?i.category_id==null:String(i.category_id)===categoryValue||String(i.category_parent_id)===categoryValue))&&[i.label,i.display_label,i.category_label].join(' ').toLowerCase().includes(search));
        const retained=catalog.find(i=>i.item_key===preserve);
        if(retained&&!filtered.includes(retained))filtered.unshift(retained);
        select.replaceChildren(new Option('Choose item',''),...filtered.map(i=>new Option((i.display_label||i.label)+' — '+i.allocatable_qty+' '+i.unit+' allocatable',i.item_key)));
        select.value=preserve||'';
      }
      function add(line={}){
        const row=document.createElement('div');row.className='border rounded-xl p-3 my-2';row.dataset.line='1';
        row.innerHTML='<label>Item<select data-item required class="w-full border rounded-xl p-2"></select></label><label>Requested quantity<input data-qty type="number" min="0.01" max="99999999.99" step="0.01" required class="border rounded-xl p-2"></label><label>Line details<input data-note maxlength="255" class="border rounded-xl p-2"></label><p data-stock></p>';
        const pick=row.querySelector('[data-item]');options(pick,line.item_key);row.querySelector('[data-qty]').value=line.requested_qty||1;row.querySelector('[data-note]').value=line.line_notes||'';
        const update=()=>{const i=catalog.find(i=>i.item_key===pick.value);row.querySelector('[data-qty]').step=i?.item_type==='equipment'?'1':'0.01';row.querySelector('[data-stock]').textContent=i?'On hand: '+i.physical_qty+'; reserved: '+i.reserved_qty+'; protected: '+(i.protected_qty??'not configured')+'. Larger requests can be submitted for review.':'';};
        pick.onchange=update;update();row.append(button('Remove',()=>row.remove()));lineRoot.append(row);
      }
      form.querySelector('[data-add]').onclick=()=>add();
      const refilter=()=>lineRoot.querySelectorAll('[data-item]').forEach(s=>options(s,s.value));
      category.onchange=refilter;form.querySelector('[data-search]').oninput=refilter;
      (existing?.items||[{item_key:preset}]).forEach(add);
      form.onsubmit=e=>{e.preventDefault();save(form,async()=>{
        const items=[...lineRoot.querySelectorAll('[data-line]')].map(row=>({item_key:row.querySelector('[data-item]').value,requested_qty:Number(row.querySelector('[data-qty]').value),notes:row.querySelector('[data-note]').value}));
        const body={purpose:form.elements.purpose.value,department:dept.value,expected_collection:form.elements.expected_collection.value,items};
        if(existing)Object.assign(body,{action:'edit',request_revision:existing.request_revision});
        await adapter.api('stock_requests.php'+(existing?'?id='+existing.id:''),existing?'PUT':'POST',body);
      });};
      form.elements.purpose.focus();
    }catch(e){error(e);}
  }
  async function open(id,mode='view'){
    try{
      const r=await adapter.api('stock_requests.php?id='+id);
      const content=panel(r.request_code+' — '+r.status+(r.self_approved?' · Self-approved':''));
      content.innerHTML='<p>'+esc(r.department)+' · '+esc(r.requested_by)+'</p><p><strong>Purpose:</strong> '+esc(r.purpose||'Not recorded — edit before review')+'</p><p>'+esc(r.reviewed_by_name?'Reviewed by '+r.reviewed_by_name+' ('+r.reviewed_role+') at '+r.reviewed_at:'')+'</p><form><div style="overflow:auto"><table class="gl-table w-full"><thead><tr><th>Item</th><th>Requested</th><th>Approved</th><th>Issued</th><th>Remaining</th><th>Availability / action</th></tr></thead><tbody></tbody></table></div><label>Reason / notes<textarea name="reason" maxlength="1000" class="w-full border rounded-xl p-2"></textarea></label></form>';
      const form=content.querySelector('form'),tbody=form.querySelector('tbody');
      r.items.forEach(line=>{
        const tr=document.createElement('tr');tr.dataset.id=line.id;
        tr.innerHTML='<td>'+esc(line.item_label)+'<br><small>'+esc(line.unit)+'</small></td><td>'+line.requested_qty+'</td><td>'+esc(line.approved_qty??'—')+'</td><td>'+line.issued_qty+'</td><td>'+line.remaining_qty+'</td><td>On hand '+esc(line.physical_qty??'unknown')+'; reserved '+esc(line.reserved_qty??0)+'; protected '+esc(line.protected_qty??'not set')+'; allocatable '+esc(line.allocatable_qty??0)+'</td>';
        if(mode==='approve')tr.lastElementChild.innerHTML+='<label>Approve<input data-qty type="number" min="0" max="'+line.requested_qty+'" step="'+(line.item_type==='equipment'?'1':'0.01')+'" value="'+Math.min(line.requested_qty,line.allocatable_qty||0)+'" required></label><label>Reduction/rejection reason<input data-reason maxlength="1000"></label><label>Protected reserve override reason<input data-override maxlength="1000"></label>';
        if(mode==='issue'&&line.remaining_qty>0)tr.lastElementChild.innerHTML+='<label>Issue now<input data-qty type="number" min="0" max="'+line.remaining_qty+'" step="'+(line.item_type==='equipment'?'1':'0.01')+'" value="0" required></label>';
        if(mode==='release'&&line.remaining_qty>0)tr.lastElementChild.innerHTML+='<label>Release<input data-qty type="number" min="0" max="'+line.remaining_qty+'" step="'+(line.item_type==='equipment'?'1':'0.01')+'" value="0" required></label>';
        tbody.append(tr);
      });
      if(r.history_warning){const warning=document.createElement('p');warning.textContent=r.history_warning;content.prepend(warning);}
      if(r.review_context){const details=document.createElement('details');const summary=document.createElement('summary');summary.textContent='Other commitments and department issuance history';details.append(summary);for(const context of r.review_context){const p=document.createElement('p');p.textContent=context.item_key+': '+context.department_issuance_last_six_completed_months.map(x=>x.department+' issued '+x.issued_qty).join('; ')+' | Other approved requests: '+context.other_approved_requests.map(x=>x.request_code+' ('+x.department+')').join(', ');details.append(p);}content.append(details);}
      if(mode==='view'){
        if(r.status==='Pending'&&r.can_edit_or_close)content.append(button('Edit pending request',()=>openCreate(null,r)));
        return;
      }
      const submit=document.createElement('button');submit.type='submit';submit.className='btn-primary';submit.textContent=mode==='approve'?'Save manager decision':mode==='issue'?'Issue selected quantities':mode==='release'?'Release selected commitments':'Close unissued remainder';form.append(submit);
      form.onsubmit=e=>{e.preventDefault();save(form,async()=>{
        const body={action:mode,request_revision:r.request_revision,reason:form.elements.reason.value};
        if(mode==='approve')body.items=[...tbody.rows].map(tr=>({id:Number(tr.dataset.id),approved_qty:Number(tr.querySelector('[data-qty]').value),reason:tr.querySelector('[data-reason]').value,override_reason:tr.querySelector('[data-override]').value}));
        if(mode==='issue')body.items=[...tbody.rows].filter(tr=>Number(tr.querySelector('[data-qty]')?.value)>0).map(tr=>({id:Number(tr.dataset.id),qty:Number(tr.querySelector('[data-qty]').value)}));
        if(mode==='release')body.reservation_releases=[...tbody.rows].filter(tr=>Number(tr.querySelector('[data-qty]')?.value)>0).map(tr=>({id:Number(tr.dataset.id),qty:Number(tr.querySelector('[data-qty]').value),request_revision:r.request_revision,reason:body.reason}));
        await adapter.api('stock_requests.php?id='+r.id,'PUT',body);
      });};
    }catch(e){error(e);}
  }
  function render(container,rows,table=false){
    container.replaceChildren();
    if(!rows.length){container.textContent='No matching stock requests.';return;}
    rows.forEach(r=>{
      const holder=document.createElement(table?'tr':'article');holder.className='border-b py-3';
      const cell=table?document.createElement('td'):holder;if(table){cell.colSpan=10;holder.append(cell);}
      cell.innerHTML='<strong>'+esc(r.request_code)+'</strong> · '+esc(r.department)+' · '+esc(r.status)+(r.self_approved?' · <strong>Self-approved</strong>':'')+(r.partial_approval?' · Partially approved':'')+'<p>'+esc(r.purpose||'Purpose not recorded')+'</p><p>'+r.items.map(i=>esc(i.item_label)+': requested '+i.requested_qty+', approved '+esc(i.approved_qty??'—')+', issued '+i.issued_qty+', remaining '+i.remaining_qty+' '+esc(i.unit)).join('<br>')+'</p>';
      if(['Approved','Partially Issued'].includes(r.status))cell.insertAdjacentHTML('beforeend','<small>Reserved for '+esc(r.reservation_age_days??0)+' day(s); expected collection: '+esc(r.expected_collection||'not specified')+'</small><br>');
      cell.append(button('Details',()=>open(r.id)));
      if(r.can_review)cell.append(button('Review',()=>open(r.id,'approve')));
      if(r.can_issue)cell.append(button('Issue',()=>open(r.id,'issue')));
      if(manager()&&['Approved','Partially Issued'].includes(r.status))cell.append(button('Release reservation',()=>open(r.id,'release')));
      if(r.can_edit_or_close&&['Pending','Approved','Partially Issued'].includes(r.status))cell.append(button('Close remainder',()=>open(r.id,'close')));
      container.append(holder);
    });
  }
  async function openPolicy(){
    try{
      const items=await adapter.api('inventory.php');const content=panel('Protected stock policy');
      content.innerHTML='<form><label>Item<select name="policy_item" required class="w-full border rounded-xl p-2"></select></label><label>Protected quantity (blank = not configured)<input name="qty" type="number" min="0" max="99999999.99" step="0.01" class="w-full border rounded-xl p-2"></label><label>Reason<textarea name="reason" required maxlength="1000" class="w-full border rounded-xl p-2"></textarea></label><p>This policy applies to new approvals. Existing commitments remain valid.</p><button type="submit" class="btn-primary">Save policy</button></form>';
      const form=content.querySelector('form');items.forEach(i=>form.elements.policy_item.add(new Option(i.display_label||i.label,i.item_key)));
      const update=()=>{const item=items.find(i=>i.item_key===form.elements.policy_item.value);form.elements.qty.value=item?.protected_qty??'';form.elements.qty.step=item?.item_type==='equipment'?'1':'0.01';};
      form.elements.policy_item.onchange=update;update();
      form.onsubmit=e=>{e.preventDefault();save(form,async()=>{const item=items.find(i=>i.item_key===form.elements.policy_item.value);await adapter.api('inventory.php?item='+encodeURIComponent(item.item_key),'PUT',{stock_version:item.stock_version,protected_qty:form.elements.qty.value===''?null:Number(form.elements.qty.value),policy_reason:form.elements.reason.value});});};
    }catch(e){error(e);}
  }
  async function openVoid(issue){
    try{
      const request=issue.request_id?await adapter.api('stock_requests.php?id='+issue.request_id):null;
      const content=panel('Void '+issue.issue_code);
      content.innerHTML='<form><p>Reverse an erroneous issuance. Normal equipment returns use Record return.</p><label>Reason<textarea name="reason" required maxlength="255" class="w-full border rounded-xl p-2"></textarea></label><label>Reversed commitment<select name="disposition" class="w-full border rounded-xl p-2"><option value="release">Release — no reissuance commitment</option></select></label><button type="submit" class="btn-primary">Void issue</button></form>';
      const form=content.querySelector('form');
      if(request&&!['Closed','Cancelled','Rejected'].includes(request.status))form.elements.disposition.add(new Option('Keep reserved for reissuance','reissue'));
      form.onsubmit=e=>{e.preventDefault();save(form,async()=>{await adapter.api('stock_issues.php?id='+issue.id,'PUT',{action:'void',reason:form.elements.reason.value,...(request?{request_revision:request.request_revision,reservation_disposition:form.elements.disposition.value}:{})});});};
    }catch(e){error(e);}
  }
  return {openCreate,open,render,close,openPolicy,openVoid};
};
