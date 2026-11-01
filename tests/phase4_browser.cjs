// Exercise actual catalog filter functions, with a minimal DOM rather than a second implementation.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const html = fs.readFileSync('php/index.html','utf8');
function source(name){
  const begin = html.indexOf('  function ' + name + '(');
  assert(begin >= 0, name);
  const end = html.indexOf('\n  }', begin) + 4;
  return html.slice(begin,end);
}
const controls = new Map();
const items = [
 {item_key:'paper',label:'Paper',item_type:'consumable',active:true,category_id:10,category_parent_id:2},
 {item_key:'mouse',label:'Mouse',item_type:'equipment',active:true,category_id:8,category_parent_id:1},
 {item_key:'unknown',label:'Unknown',item_type:'consumable',active:true,category_id:null,category_parent_id:null},
 {item_key:'old',label:'Old equipment',item_type:'equipment',active:false,category_id:8,category_parent_id:1}
];
let type = 'all', selectedItem = '';
const ctx = vm.createContext({
 state: {inventory:items,showInactiveInventory:false},
 document: {getElementById:id => controls.get(id)},
 tableTypeFilter:()=>type,tableItemKeyFilter:()=>selectedItem,
 rowMatchesTypeFilter:(actual,filter)=> filter==='all'||actual===filter,
 activeItems:()=>items.filter(i=>i.active),itemDisplayLabel:i=>i.label
});
for(const name of ['categoryMatches','catalogMetaForItemKey','rowMatchesCatalogFilters','catalogItemsForTypeFilter']) vm.runInContext(source(name),ctx);
controls.set('inventoryTableCategorySelect',{value:'1'});
assert.equal(ctx.rowMatchesCatalogFilters('equipment','mouse','inventoryTable'),true);
assert.equal(ctx.rowMatchesCatalogFilters('consumable','paper','inventoryTable'),false);
assert.equal(ctx.categoryMatches(items[1],'8'),true);
assert.equal(ctx.categoryMatches(items[0],'uncategorized'),false);
assert.equal(ctx.categoryMatches(items[2],'uncategorized'),true);
assert.equal(ctx.categoryMatches(items[2],''),true);
type='consumable';
assert.equal(ctx.rowMatchesCatalogFilters(null,'mouse','inventoryTable'),false);
type='all'; selectedItem='paper';
assert.equal(ctx.rowMatchesCatalogFilters(null,'mouse','inventoryTable'),false);
selectedItem='';
ctx.state.stockIssues=[{item_key:'hidden-equipment',item_type:'equipment',category_id:8,category_parent_id:1}];
assert.equal(ctx.rowMatchesCatalogFilters(null,'hidden-equipment','inventoryTable'),true);
assert.equal(ctx.categoryMatches({},'1'),false);
assert.equal(ctx.categoryMatches({},'uncategorized'),true);
assert.deepEqual(Array.from(ctx.catalogItemsForTypeFilter('equipment'),i=>i.item_key),['mouse']);
ctx.state.showInactiveInventory=true;
assert.deepEqual(Array.from(ctx.catalogItemsForTypeFilter('equipment'),i=>i.item_key),['mouse','old']);
// Execute the actual renderer and capture variants sent to its stock rollup.
let rolledUp = [];
Object.assign(ctx, {
 tableSearchQuery:()=>'',rowMatchesSearch:()=>true,itemPlacementLabel:()=>'',
 groupEquipmentItems:rows=>rows.length ? [['Devices',rows]] : [],
 equipmentGroupMatchesSearch:()=>true,equipmentGroupKey:x=>x,isEquipmentGroupExpanded:()=>false,
 rollupEquipmentGroup:rows=>{ rolledUp=rows; return {}; },
 renderEquipmentGroupHeaderRow:()=>'<tr></tr>',renderConsumableInventoryRow:()=>'<tr></tr>'
});
ctx.state.showInactiveInventory=false;
controls.set('inventoryTableBody',{innerHTML:''});
vm.runInContext(source('renderInventory'),ctx);
ctx.renderInventory('equipment');
assert.deepEqual(Array.from(rolledUp,i=>i.item_key),['mouse']);
// Existing fractional consumables must be editable while equipment stays whole.
let selectedType='consumable';
for(const id of ['itmCurrent','itmMinWrap','itmMaxWrap','itmConsumableNameWrap','itmEquipmentNamesWrap','itmName','itmEquipmentGroup','itmVariant','itmLocationId']) controls.set(id,{value:'',classList:{toggle(){}}});
ctx.document.querySelector=()=>({value:selectedType});
ctx.populateEquipmentGroupDatalist=()=>{};ctx.syncEquipmentPlacementFields=()=>{};
vm.runInContext(source('itemTypeToggle'),ctx);
ctx.itemTypeToggle();assert.equal(controls.get('itmCurrent').step,'0.01');
selectedType='equipment';ctx.itemTypeToggle();assert.equal(controls.get('itmCurrent').step,'1');
// Parse every inline script, including the public and department pages.
for(const file of ['php/index.html','php/department-api.html','php/vendor-apply.html']){
 for(const match of fs.readFileSync(file,'utf8').matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)){
  if(!/src=|application\/ld\+json/.test(match[1])) new vm.Script(match[2],{filename:file});
 }
}
console.log('PASS Phase 4 browser filters: parent/child/uncategorized, type/item composition, active records and inline script syntax');
