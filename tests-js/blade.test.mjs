import { JSDOM } from 'jsdom';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../resources/dist/', import.meta.url));
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

function boot({ search = '' } = {}) {
  const dom = new JSDOM(`<!doctype html><html><head><meta name="csrf-token" content="TOK"></head><body>
    <button type="button" id="p1-open" class="payments-open">Buy now</button>
    <dialog id="p1"><strong data-payments-title></strong><button data-payments-close>x</button><div data-payments-body></div></dialog>
  </body></html>`, { url: 'http://app.test/' + search, runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  // jsdom has no <dialog>.showModal
  w.HTMLElement.prototype.showModal = function () { this.setAttribute('open', ''); };
  w.HTMLElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
  Object.defineProperty(w.HTMLElement.prototype, 'open', { get() { return this.hasAttribute('open'); } });

  const calls = [];
  let polls = 0;
  w.fetch = async (url, init) => {
    const path = new URL(url, 'http://app.test').pathname.replace('/api/payments', '');
    const body = init.body ? JSON.parse(init.body) : null;
    calls.push({ method: init.method, path, headers: init.headers, body });
    const reply = (b, status = 200) => ({ ok: status < 400, status, json: async () => b });
    if (path === '/gateways') return reply({ data: [
      { slug: 'mpesa', name: 'M-Pesa', driver: 'mpesa', currency: 'LSL', public: {} },
      { slug: 'cpay', name: 'CPay', driver: 'cpay', currency: 'LSL', public: {} } ] });
    if (path === '/charge') return body.gateway === 'cpay'
      ? reply({ status: 'pending', action: 'otp', reference: 'R2', message: 'Enter the code we sent.' })
      : reply({ status: 'pending', action: 'poll', reference: 'R1', message: 'Approve on your phone.' });
    if (path.startsWith('/status/')) return reply({ status: ++polls >= 2 ? 'completed' : 'pending', reference: path.split('/').pop() });
    if (path === '/confirm') return reply({ status: 'completed', reference: 'R2' });
    throw new Error('unmocked ' + path);
  };

  w.eval(readFileSync(root + 'payments-core.global.js', 'utf8'));
  w.eval(readFileSync(root + 'payments-blade.js', 'utf8'));
  return { w, doc: w.document, calls };
}
const until = async (fn, label) => { const end = Date.now() + 1500; while (!fn()) { if (Date.now() > end) assert.fail('timeout: ' + label); await wait(3); } };
const body = (d) => d.querySelector('[data-payments-body]').textContent.replace(/\s+/g, ' ').trim();
const btn = (d, t) => [...d.querySelectorAll('button, a')].find((b) => b.textContent.includes(t));
const type = (w, el, v) => { el.value = v; el.dispatchEvent(new w.Event('input', { bubbles: true })); };

// 1. full M-Pesa flow
{
  const { w, doc, calls } = boot();
  const events = [];
  doc.getElementById('p1').addEventListener('payments:success', () => events.push('success'));
  w.PaymentsBlade.mount('p1', { baseUrl: '/api/payments', productIds: [12], gateways: [], title: 'Pay for song', pollInterval: 5 });
  assert.equal(doc.querySelector('[data-payments-title]').textContent, 'Pay for song');

  doc.getElementById('p1-open').click();
  await until(() => body(doc).includes('M-Pesa'), 'gateways');
  assert.ok(doc.getElementById('p1').hasAttribute('open'));
  assert.equal(btn(doc, 'Choose a payment method').disabled, true);

  doc.querySelector('input[type=radio]').dispatchEvent(new w.Event('change'));
  assert.ok(btn(doc, 'Pay with M-Pesa').disabled, 'needs a phone first');
  type(w, doc.querySelector('input[type=tel]'), '58123456');
  assert.equal(btn(doc, 'Pay with M-Pesa').disabled, false);
  btn(doc, 'Pay with M-Pesa').click();

  await until(() => body(doc).includes('Approve on your phone'), 'polling');
  await until(() => body(doc).includes('Payment complete'), 'success');
  assert.deepEqual(events, ['success']);
  const charge = calls.find((c) => c.path === '/charge');
  assert.deepEqual(charge.body, { gateway: 'mpesa', product_ids: [12], msisdn: '58123456' });
  assert.equal(charge.headers['X-CSRF-TOKEN'], 'TOK', 'CSRF header sent');

  btn(doc, 'Done').click();
  assert.equal(doc.getElementById('p1').hasAttribute('open'), false, 'Done closes');
  console.log('ok  Blade M-Pesa flow incl. CSRF header + payments:success event');
}

// 2. OTP keeps typed code through busy re-render
{
  const { w, doc, calls } = boot();
  w.PaymentsBlade.mount('p1', { baseUrl: '/api/payments', productIds: [3], gateways: ['cpay'], pollInterval: 5 });
  doc.getElementById('p1-open').click();
  await until(() => body(doc).includes('Pay with CPay'), 'single gateway preselected + filtered');
  assert.ok(!body(doc).includes('M-Pesa'));
  type(w, doc.querySelector('input[type=tel]'), '58000000');
  btn(doc, 'Pay with CPay').click();
  await until(() => body(doc).includes('Enter the code we sent'), 'otp');
  assert.equal(btn(doc, 'Confirm').disabled, true, 'disabled with empty otp');
  type(w, doc.querySelector('input[inputmode=numeric]'), '654321');
  assert.equal(btn(doc, 'Confirm').disabled, false);
  btn(doc, 'Confirm').click();
  await until(() => body(doc).includes('Payment complete'), 'success');
  assert.deepEqual(calls.find((c) => c.path === '/confirm').body, { reference: 'R2', otp: '654321' });
  console.log('ok  Blade CPay OTP flow + gateway filter');
}

// 3. return from redirect resumes by ?reference=
{
  const { w, doc, calls } = boot({ search: '?reference=PAY99&status=pending' });
  w.PaymentsBlade.mount('p1', { baseUrl: '/api/payments', productIds: [], gateways: [], pollInterval: 5 });
  await until(() => body(doc).includes('Payment complete'), 'resumed to success');
  assert.ok(calls.some((c) => c.path === '/status/PAY99'));
  console.log('ok  Blade resumes after redirect (?reference=)');
}

// 4. hostile server text is rendered as text, never as HTML
{
  const { w, doc } = boot();
  const evil = '<img src=x onerror="window.pwned=1">';
  w.fetch = async (url) => {
    const path = new URL(url, 'http://app.test').pathname.replace('/api/payments', '');
    if (path === '/gateways') return { ok: true, status: 200, json: async () => ({ data: [{ slug: 'mpesa', name: 'M-Pesa', driver: 'mpesa', currency: 'LSL', public: {} }] }) };
    return { ok: false, status: 422, json: async () => ({ success: false, status: 'failed', message: evil }) };
  };
  w.PaymentsBlade.mount('p1', { baseUrl: '/api/payments', productIds: [1], gateways: [], pollInterval: 5 });
  doc.getElementById('p1-open').click();
  await until(() => body(doc).includes('Pay with M-Pesa'), 'idle');
  type(w, doc.querySelector('input[type=tel]'), '5');
  btn(doc, 'Pay with M-Pesa').click();
  await until(() => body(doc).includes('<img'), 'failed message shown');
  assert.equal(doc.querySelectorAll('img').length, 0, 'no <img> element was created');
  assert.equal(w.pwned, undefined);
  console.log('ok  hostile server message rendered as inert text');
}
console.log('\nBlade checks passed');
process.exit(0);
