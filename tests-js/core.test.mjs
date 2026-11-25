import test from 'node:test';
import assert from 'node:assert/strict';
import { createPaymentFlow, STEPS } from '../resources/js/core/payments-core.js';

// A tiny fake API: routes -> queue of responses.
function mockApi(routes) {
  const calls = [];
  const fetch = async (url, init) => {
    const path = new URL(url, 'http://x').pathname;
    calls.push({ method: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
    const key = `${init.method} ${path.replace('/api/payments', '')}`.replace(/\/status\/.+/, '/status/:ref');
    const queue = routes[key];
    if (!queue) throw new Error(`unmocked ${key}`);
    const next = typeof queue === 'function' ? queue() : queue.length > 1 ? queue.shift() : queue[0];
    if (next instanceof Error) throw next;
    return { ok: (next.http ?? 200) < 400, status: next.http ?? 200, json: async () => next.body };
  };
  return { fetch, calls };
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (flow, step, ms = 1000) => {
  const end = Date.now() + ms;
  while (flow.getState().step !== step) {
    if (Date.now() > end) assert.fail(`never reached "${step}", stuck at "${flow.getState().step}"`);
    await wait(2);
  }
};
const base = { pollInterval: 5, maxPolls: 5 };

test('M-Pesa: charge -> poll (pending, pending, completed) -> success', async () => {
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'poll', reference: 'PAY1', message: 'Approve on phone' } }],
    'GET /status/:ref': [
      { body: { status: 'pending', action: 'none', reference: 'PAY1' } },
      { body: { status: 'pending', action: 'none', reference: 'PAY1' } },
      { body: { status: 'completed', action: 'none', reference: 'PAY1' } },
    ],
  });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch, headers: async () => ({ Authorization: 'Bearer t' }) });
  await flow.start({ gateway: 'mpesa', productIds: [12, 15], msisdn: '58123456' });
  assert.equal(flow.getState().step, STEPS.POLLING);
  assert.equal(flow.getState().reference, 'PAY1');
  await until(flow, STEPS.SUCCESS);

  const charge = api.calls[0];
  assert.deepEqual(charge.body, { gateway: 'mpesa', product_ids: [12, 15], msisdn: '58123456' });
  assert.equal(charge.headers.Authorization, 'Bearer t');
  assert.equal(charge.headers['Content-Type'], 'application/json');
  assert.ok(api.calls.filter((c) => c.path.includes('/status/PAY1')).length >= 3);
  flow.destroy();
});

test('CPay: charge -> otp -> confirm -> success', async () => {
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'otp', reference: 'PAY2' } }],
    'POST /confirm': [{ body: { status: 'completed', action: 'none', reference: 'PAY2' } }],
  });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'cpay', productId: 7, msisdn: '58123456' });
  assert.equal(flow.getState().step, STEPS.OTP);
  await flow.confirmOtp('123456');
  assert.equal(flow.getState().step, STEPS.SUCCESS);
  assert.deepEqual(api.calls[1].body, { reference: 'PAY2', otp: '123456' });
  assert.equal(api.calls[0].body.product_id, 7);
  assert.equal('product_ids' in api.calls[0].body, false);
});

test('PayPal: redirect action calls redirect() once and does not poll', async () => {
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'redirect', reference: 'PAY3', redirect_url: 'https://paypal.test/approve' } }],
  });
  const redirected = [];
  const flow = createPaymentFlow({ ...base, fetch: api.fetch, redirect: (u) => redirected.push(u) });
  await flow.start({ gateway: 'paypal', productId: 1, returnUrl: 'https://app.test/done' });
  assert.equal(flow.getState().step, STEPS.REDIRECTING);
  assert.deepEqual(redirected, ['https://paypal.test/approve']);
  assert.equal(api.calls[0].body.return_url, 'https://app.test/done');
  await wait(30);
  assert.equal(api.calls.length, 1, 'no polling after redirect');
});

test('card iframe: html action exposes html and polls', async () => {
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'html', reference: 'PAY4', html: '<iframe></iframe>' } }],
    'GET /status/:ref': [{ body: { status: 'completed', reference: 'PAY4' } }],
  });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'cpay', productId: 1, method: 'card' });
  assert.equal(flow.getState().html, '<iframe></iframe>');
  await until(flow, STEPS.SUCCESS);
});

test('server says failed (422 body) -> failed with message', async () => {
  const api = mockApi({ 'POST /charge': [{ http: 422, body: { success: false, status: 'failed', message: 'M-Pesa could not start the payment.' } }] });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'mpesa', productId: 1, msisdn: '5' });
  assert.equal(flow.getState().step, STEPS.FAILED);
  assert.equal(flow.getState().message, 'M-Pesa could not start the payment.');
});

test('validation error (Laravel 422 shape) surfaces the first field error', async () => {
  const api = mockApi({ 'POST /charge': [{ http: 422, body: { message: 'The gateway field is required.', errors: { gateway: ['The gateway field is required.'] } } }] });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'x' });
  assert.equal(flow.getState().step, STEPS.FAILED);
  assert.equal(flow.getState().message, 'The gateway field is required.');
});

test('401 without a payment body -> failed with a readable message', async () => {
  const api = mockApi({ 'POST /charge': [{ http: 401, body: { message: 'Unauthenticated.' } }] });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'mpesa', productId: 1 });
  assert.equal(flow.getState().message, 'Unauthenticated.');
});

test('network error on start -> failed, flow is reusable', async () => {
  const api = mockApi({ 'POST /charge': [new Error('offline')] });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'mpesa', productId: 1 });
  assert.equal(flow.getState().step, STEPS.FAILED);
  assert.equal(flow.getState().message, 'offline');
  flow.reset();
  assert.equal(flow.getState().step, STEPS.IDLE);
});

test('missing gateway fails fast without calling the API', async () => {
  const api = mockApi({});
  const flow = createPaymentFlow({ fetch: api.fetch });
  await flow.start({ productId: 1 });
  assert.equal(flow.getState().step, STEPS.FAILED);
  assert.equal(api.calls.length, 0);
});

test('polling gives up after maxPolls -> timeout, keepWaiting resumes', async () => {
  let n = 0;
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'poll', reference: 'PAY5' } }],
    'GET /status/:ref': () => (++n > 6 ? { body: { status: 'completed', reference: 'PAY5' } } : { body: { status: 'pending', reference: 'PAY5' } }),
  });
  const flow = createPaymentFlow({ ...base, maxPolls: 3, fetch: api.fetch });
  await flow.start({ gateway: 'mpesa', productId: 1, msisdn: '5' });
  await until(flow, STEPS.TIMEOUT);
  flow.keepWaiting();
  await until(flow, STEPS.TIMEOUT); // 3 more polls, still pending (n=4..6)
  flow.keepWaiting();
  await until(flow, STEPS.SUCCESS);
});

test('transient network errors while polling do not fail the payment', async () => {
  let n = 0;
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'poll', reference: 'PAY6' } }],
    'GET /status/:ref': () => {
      n++;
      if (n === 1) return new Error('blip');
      return { body: { status: 'completed', reference: 'PAY6' } };
    },
  });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  await flow.start({ gateway: 'ecocash', productId: 1, msisdn: '5' });
  await until(flow, STEPS.SUCCESS);
});

test('reset() cancels an in-flight poll: a stale poll cannot revive the flow', async () => {
  const api = mockApi({
    'POST /charge': [{ body: { status: 'pending', action: 'poll', reference: 'PAY7' } }],
    'GET /status/:ref': [{ body: { status: 'completed', reference: 'PAY7' } }],
  });
  const flow = createPaymentFlow({ ...base, pollInterval: 20, fetch: api.fetch });
  await flow.start({ gateway: 'mpesa', productId: 1, msisdn: '5' });
  flow.reset();
  await wait(60);
  assert.equal(flow.getState().step, STEPS.IDLE);
  assert.equal(api.calls.length, 1);
});

test('resume(reference) after a redirect return polls immediately', async () => {
  const api = mockApi({ 'GET /status/:ref': [{ body: { status: 'completed', reference: 'PAY8' } }] });
  const flow = createPaymentFlow({ ...base, fetch: api.fetch });
  flow.resume('PAY8');
  await until(flow, STEPS.SUCCESS);
  assert.equal(api.calls[0].path, '/api/payments/status/PAY8');
});

test('loadGateways stores the list and returns it', async () => {
  const api = mockApi({ 'GET /gateways': [{ body: { data: [{ slug: 'mpesa', name: 'M-Pesa', driver: 'mpesa', currency: 'LSL', public: {} }] } }] });
  const flow = createPaymentFlow({ fetch: api.fetch });
  const list = await flow.loadGateways();
  assert.equal(list[0].slug, 'mpesa');
  assert.equal(flow.getState().gateways.length, 1);
});

test('subscribe emits immediately and on every change; unsubscribe stops it', async () => {
  const api = mockApi({ 'POST /charge': [{ body: { status: 'completed', reference: 'PAY9' } }] });
  const flow = createPaymentFlow({ fetch: api.fetch });
  const steps = [];
  const off = flow.subscribe((s) => steps.push(s.step));
  await flow.start({ gateway: 'mpesa', productId: 1, msisdn: '5' });
  off();
  flow.reset();
  assert.deepEqual(steps, ['idle', 'charging', 'success']);
});
