// Exercise the actual monthly form handlers without installing browser packages.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../php/index.html'), 'utf8');
const start = html.indexOf('  function defaultCloseMonthPick(){');
const end = html.indexOf('  function normalizeItemLabel(', start);
assert(start > 0 && end > start);
const elements = new Map();
function el(id) {
  if (!elements.has(id)) {
    let value = '';
    const classes = new Set();
    elements.set(id, { dataset: {}, textContent: '', disabled: false, readOnly: false,
      get value() { return value; }, set value(v) { value = String(v); },
      classList: { add: c => classes.add(c), remove: c => classes.delete(c),
        toggle(c, flag) { if (flag) classes.add(c); else classes.delete(c); }, contains: c => classes.has(c) },
      handlers: {}, addEventListener(event, handler) { this.handlers[event] = handler; },
      focus() {}, reportValidity() {
        return ['closeOpening','closeReceived','closeClosing'].every(key => el(key).value !== '')
          && el('closeMonthPick').value <= el('closeMonthPick').max;
      },
    });
  }
  return elements.get(id);
}
const errors = [], writes = [], notices = [];
let getImpl, confirmImpl = async () => true;
const context = vm.createContext({
  document: { getElementById: el }, window: {},
  state: { inventory: [{ item_key: 'fixture', item_type: 'consumable', unit: 'pcs', label: 'Fixture' }] },
  apiGet: (...args) => getImpl(...args), apiSend: async (...args) => {
    writes.push(args); return { computed_usage: 10, warnings: [], stock_changed: false };
  },
  itemDisplayLabel: it => it.label, openModal() {}, closeModal() {},
  showError: error => errors.push(error), showConfirmModal: (...args) => confirmImpl(...args),
  loadInventory: async () => {}, loadDemandInsights: async () => {}, renderDashboard() {},
  showNoticeModal: notice => notices.push(notice),
});
vm.runInContext(html.slice(start, end) + '\nglobalThis.reloadMonth = refreshCloseMonthFromServer; globalThis.preview = updateCloseMonthPreview;', context);
const response = (changes={}) => ({ usage_version: '0', context_version: 'token', reason_required: false,
  opening_qty: null, opening_note: 'Unknown historical opening', received_qty: 0, po_received_qty: 0,
  received_note: 'PO receipts', existing_closing_qty: null, warnings: [], can_close: true,
  latest_completed_month: '2026-09', ...changes });
function deferred() { let resolve; const promise = new Promise(r => resolve = r); return {promise, resolve}; }
async function submit() {
  return el('closeMonthForm').handlers.submit.call(el('closeMonthForm'), { preventDefault() {} });
}
(async () => {
  el('closeMonthItemKey').value = 'fixture'; el('closeMonthPick').value = '2026-08';
  el('closeMonthUnit').textContent = 'pcs';
  getImpl = async () => response();
  await context.reloadMonth();
  assert.equal(el('closeOpening').value, '', 'unknown opening is blank');
  assert.equal(el('closeOpening').readOnly, false, 'unknown opening can be entered');
  assert.equal(el('closeClosing').value, '');
  await submit(); assert.equal(writes.length, 0, 'blank count is not converted to zero');
  el('closeOpening').value = 30; el('closeClosing').value = 20;
  context.preview(); assert.match(el('closeMonthPreview').textContent, /10 pcs.*unchanged/);
  el('closeClosing').value = 31;
  await submit(); assert.equal(writes.length, 0, 'impossible arithmetic is blocked');
  el('closeClosing').value = 20;
  await submit(); assert.equal(writes.length, 1);
  assert.equal(writes[0][2].usage_version, '0');
  assert.equal(writes[0][2].context_version, 'token');
  assert(!('stock_version' in writes[0][2]), 'historical close uses monthly revision');
  assert.match(notices.at(-1).message, /Current stock was not changed/);

  getImpl = async () => response({ opening_qty: 30, existing_closing_qty: 20, reason_required: true });
  await context.reloadMonth();
  await submit(); assert.equal(writes.length, 1, 'correction requires a reason');
  el('closeMonthReason').value = 'Reviewed source records';
  await submit(); assert.equal(writes.length, 2);
  getImpl = async () => { throw {error: 'Simulated load failure'}; };
  await context.reloadMonth();
  assert(el('closeMonthSubmit').disabled, 'failed month load keeps submit disabled');
  assert.equal(el('closeClosing').value, '', 'old month count cleared');
  assert.equal(el('closeMonthForm').dataset.contextVersion, undefined);
  await submit(); assert.equal(writes.length, 2);

  const first = deferred(), second = deferred();
  getImpl = () => first.promise;
  const oldLoad = context.reloadMonth();
  getImpl = () => second.promise;
  const newLoad = context.reloadMonth();
  second.resolve(response({ opening_qty: 40, context_version: 'new' }));
  await newLoad;
  first.resolve(response({ opening_qty: 90, context_version: 'old' }));
  await oldLoad;
  assert.equal(el('closeOpening').value, '40', 'older response cannot overwrite newer selection, even for the same month');
  assert.equal(el('closeMonthForm').dataset.contextVersion, 'new');

  getImpl = async () => response({can_close:false});
  await context.reloadMonth();
  assert(el('closeMonthSubmit').disabled);
  assert.match(el('closeMonthWarnings').textContent, /completed calendar months/);
  getImpl = async () => response({ opening_qty:30, warnings:['Review adjacent month'], usage_conflict:true, recorded_usage:99, close_usage:10 });
  await context.reloadMonth();
  assert.match(el('closeMonthWarnings').textContent, /Recorded usage: 99/);
  el('closeClosing').value = 20;
  const confirmation = deferred(); confirmImpl = () => confirmation.promise;
  const pending = submit();
  await context.reloadMonth();
  confirmation.resolve(true); await pending;
  assert.equal(writes.length, 2, 'a changed monthly context cannot be submitted through an older confirmation');
  assert.match(errors.at(-1), /selected month changed/);
  console.log('PASS Phase 3 browser handlers: unknown balances, validation, revisions, reasons, stale responses, load failures, confirmation changes and notices');
})().catch(error => { console.error(error); process.exitCode = 1; });
