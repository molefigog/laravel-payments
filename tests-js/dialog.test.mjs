import { JSDOM } from 'jsdom';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
const repo = fileURLToPath(new URL('..', import.meta.url));
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const dom = new JSDOM('<!doctype html><div id="app"></div>', { pretendToBeVisual: true });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'Element', 'SVGElement', 'Event', 'MouseEvent']) {
  Object.defineProperty(globalThis, k, { value: dom.window[k] ?? dom.window, configurable: true, writable: true });
}
Object.defineProperty(globalThis, 'navigator', { value: dom.window.navigator, configurable: true });

// ---- compile the SFC to an ES module on the fly (no bundler) ----
const file = repo + 'resources/js/vue/PaymentDialog.vue';
const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file });
const script = compileScript(descriptor, { id: 'pd', inlineTemplate: true, templateOptions: { compilerOptions: {} } });
mkdirSync(repo + '.tmp-ui', { recursive: true });
writeFileSync(repo + '.tmp-ui/PaymentDialog.compiled.js', script.content.replace("from './usePayment.js'", "from '../resources/js/vue/usePayment.js'"));
const { default: PaymentDialog } = await import(pathToFileURL(repo + '.tmp-ui/PaymentDialog.compiled.js').href);
const { createApp, h, nextTick, ref, defineComponent } = await import('vue');

// ---- Quasar stubs ----
const slotted = (tag = 'div') => defineComponent({ inheritAttrs: true, setup: (_, { slots }) => () => h(tag, slots.default?.()) });
const stubs = {
  QDialog: defineComponent({ props: ['modelValue'], setup: (p, { slots }) => () => (p.modelValue ? h('div', { class: 'dlg' }, slots.default?.()) : null) }),
  QCard: slotted(), QCardSection: slotted(), QList: slotted(), QItem: defineComponent({ setup: (_, { slots }) => () => h('div', { class: 'item' }, slots.default?.()) }),
  QItemSection: slotted('span'), QSpace: slotted('span'), QIcon: defineComponent({ props: ['name'], setup: (p) => () => h('i', { class: 'icon' }, p.name) }),
  QSpinner: slotted('span'), QSpinnerDots: slotted('span'),
  QRadio: defineComponent({ props: ['modelValue', 'val'], emits: ['update:modelValue'], setup: (p, { emit }) => () => h('input', { type: 'radio', value: p.val, checked: p.modelValue === p.val, onChange: () => emit('update:modelValue', p.val) }) }),
  QBtn: defineComponent({ props: ['label', 'disable', 'loading'], setup: (p, { slots }) => () => h('button', { disabled: !!p.disable, class: 'btn' }, p.label ?? slots.default?.()) }),
  QInput: defineComponent({ props: ['modelValue', 'label'], emits: ['update:modelValue'], setup: (p, { emit }) => () => h('input', { class: 'inp', placeholder: p.label, value: p.modelValue, onInput: (e) => emit('update:modelValue', e.target.value) }) }),
};

function mount(propsExtra = {}) {
  const open = ref(true);
  const events = [];
  const root = document.getElementById('app');
  root.innerHTML = '';
  const app = createApp({ render: () => h(PaymentDialog, { modelValue: open.value, 'onUpdate:modelValue': (v) => (open.value = v), productIds: [12], pollInterval: 5, onSuccess: () => events.push('success'), onFailed: () => events.push('failed'), ...propsExtra }) });
  Object.entries(stubs).forEach(([n, c]) => app.component(n, c));
  app.directive('ripple', {});
  app.mount(root);
  return { root, open, events, text: () => root.textContent.replace(/\s+/g, ' ').trim() };
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (fn, label) => { const end = Date.now() + 1500; while (!fn()) { if (Date.now() > end) assert.fail('timeout: ' + label); await wait(3); } };
const setInput = async (el, v) => { el.value = v; el.dispatchEvent(new dom.window.Event('input')); await nextTick(); };
const button = (root, label) => [...root.querySelectorAll('button')].find((b) => b.textContent.includes(label));

const gateways = { data: [ { slug: 'mpesa', name: 'M-Pesa', driver: 'mpesa', currency: 'LSL', public: {} }, { slug: 'cpay', name: 'CPay', driver: 'cpay', currency: 'LSL', public: {} }, { slug: 'paypal', name: 'PayPal', driver: 'paypal', currency: 'USD', public: { client_id: 'x' } } ] };
let charges = [], statusN = 0;
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/payments', '');
  const body = init.body ? JSON.parse(init.body) : null;
  const reply = (b, status = 200) => ({ ok: status < 400, status, json: async () => b });
  if (path === '/gateways') return reply(gateways);
  if (path === '/charge') { charges.push(body); return body.gateway === 'cpay' ? reply({ status: 'pending', action: 'otp', reference: 'R2', message: 'Enter the code we sent.' }) : reply({ status: 'pending', action: 'poll', reference: 'R1', message: 'Approve on your phone.' }); }
  if (path.startsWith('/status/')) return reply({ status: ++statusN >= 3 ? 'completed' : 'pending', reference: 'R1' });
  if (path === '/confirm') return reply({ status: 'completed', reference: 'R2' });
  throw new Error('unmocked ' + path);
};

// 1. M-Pesa: pick gateway, type phone, pay, wait, success
{
  const ui = mount();
  await until(() => ui.text().includes('M-Pesa'), 'gateways listed');
  assert.ok(ui.text().includes('PayPal'));
  assert.ok(button(ui.root, 'Choose a payment method').disabled, 'pay disabled until a method is chosen');

  const radios = ui.root.querySelectorAll('input[type=radio]');
  radios[0].dispatchEvent(new dom.window.Event('change')); await nextTick();
  assert.ok(ui.text().includes('Pay with M-Pesa'));
  assert.ok(button(ui.root, 'Pay with M-Pesa').disabled, 'disabled without a phone');

  await setInput(ui.root.querySelector('input.inp'), '58123456');
  const pay = button(ui.root, 'Pay with M-Pesa'); assert.equal(pay.disabled, false);
  pay.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
  await until(() => ui.text().includes('Approve on your phone'), 'polling view');
  await until(() => ui.text().includes('Payment complete'), 'success view');
  assert.deepEqual(charges[0], { gateway: 'mpesa', product_ids: [12], msisdn: '58123456' });
  assert.deepEqual(ui.events, ['success']);

  button(ui.root, 'Done').dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
  await nextTick();
  assert.equal(ui.open.value, false, 'Done closes the dialog');
  console.log('ok  M-Pesa flow: list -> phone -> polling -> success -> close');
}

// 2. CPay: OTP step
{
  charges = [];
  const ui = mount();
  await until(() => ui.text().includes('CPay'), 'gateways');
  ui.root.querySelectorAll('input[type=radio]')[1].dispatchEvent(new dom.window.Event('change')); await nextTick();
  await setInput(ui.root.querySelector('input.inp'), '58000000');
  button(ui.root, 'Pay with CPay').dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
  await until(() => ui.text().includes('Enter the code we sent'), 'otp view');
  await setInput(ui.root.querySelector('input.inp'), '654321');
  button(ui.root, 'Confirm').dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
  await until(() => ui.text().includes('Payment complete'), 'success after otp');
  console.log('ok  CPay flow: otp -> confirm -> success');
}

// 3. PayPal does not ask for a phone; gateways prop filters the list
{
  const ui = mount({ gateways: ['paypal'] });
  await until(() => ui.text().includes('PayPal'), 'filtered list');
  assert.ok(!ui.text().includes('M-Pesa'), 'filter hides other gateways');
  assert.equal(ui.root.querySelectorAll('input.inp').length, 0, 'no phone input for paypal');
  assert.ok(ui.text().includes('Pay with PayPal'), 'single gateway is preselected');
  console.log('ok  gateway filter + paypal without phone + single gateway preselected');
}
console.log('\nUI checks passed');
process.exit(0);
