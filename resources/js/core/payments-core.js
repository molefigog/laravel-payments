/**
 * Headless payment flow for laravel-payments. No framework, no DOM (except the default redirect).
 * Every UI (Vue/Quasar, Blade, Livewire, React ...) is a thin view over this state machine.
 *
 * Steps: idle -> charging -> (otp | polling | redirecting | html) -> success | failed | timeout
 */

export const STEPS = Object.freeze({
  IDLE: 'idle',
  CHARGING: 'charging',
  OTP: 'otp',
  POLLING: 'polling',
  REDIRECTING: 'redirecting',
  HTML: 'html',
  SUCCESS: 'success',
  FAILED: 'failed',
  TIMEOUT: 'timeout',
});

const initialState = () => ({
  step: STEPS.IDLE,
  busy: false,
  reference: null,
  message: null,
  redirectUrl: null,
  html: null,
  gateways: [],
  result: null,
});

export function createPaymentFlow(options = {}) {
  const cfg = {
    baseUrl: '/api/payments',
    headers: {}, // object, or (async) function returning an object (e.g. bearer token)
    credentials: 'same-origin',
    pollInterval: 3000,
    maxPolls: 60,
    fetch: typeof fetch !== 'undefined' ? fetch.bind(globalThis) : undefined,
    redirect: (url) => {
      if (typeof window !== 'undefined') window.location.assign(url);
    },
    ...options,
  };

  let state = initialState();
  const listeners = new Set();
  let timer = null;
  let run = 0; // bumps on every start/stop so stale polls can't touch a newer flow

  const emit = () => listeners.forEach((fn) => fn({ ...state }));
  const set = (patch) => {
    state = { busy: false, ...state, ...patch, ...(patch.busy === undefined ? { busy: false } : {}) };
    emit();
  };

  async function request(method, path, body) {
    if (!cfg.fetch) throw new Error('No fetch implementation available.');

    const extra = typeof cfg.headers === 'function' ? await cfg.headers() : cfg.headers;
    const headers = { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...extra };

    const res = await cfg.fetch(cfg.baseUrl.replace(/\/$/, '') + path, {
      method,
      headers,
      credentials: cfg.credentials,
      body: body ? JSON.stringify(body) : undefined,
    });

    let data = null;
    try {
      data = await res.json();
    } catch {
      data = null;
    }

    return { ok: res.ok, httpStatus: res.status, data };
  }

  function failFrom({ httpStatus, data }, fallback) {
    const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return data?.message && !firstError ? data.message : firstError || fallback || `Request failed (${httpStatus}).`;
  }

  function applyResult(r) {
    const base = { reference: r.reference ?? state.reference, message: r.message ?? null, result: r };

    if (r.status === 'completed') return set({ ...base, step: STEPS.SUCCESS });
    if (r.status === 'failed') return set({ ...base, step: STEPS.FAILED });

    switch (r.action) {
      case 'otp':
        return set({ ...base, step: STEPS.OTP });
      case 'redirect':
        set({ ...base, step: STEPS.REDIRECTING, redirectUrl: r.redirect_url ?? null });
        if (r.redirect_url) cfg.redirect?.(r.redirect_url);
        return;
      case 'html':
        set({ ...base, step: STEPS.HTML, html: r.html ?? null, redirectUrl: r.redirect_url ?? null });
        return startPolling();
      default:
        set({ ...base, step: STEPS.POLLING });
        return startPolling();
    }
  }

  function stopPolling() {
    run++;
    if (timer) clearTimeout(timer);
    timer = null;
  }

  function startPolling({ immediate = false } = {}) {
    stopPolling();
    const id = run;
    let polls = 0;

    const tick = async () => {
      if (id !== run || !state.reference) return;
      polls++;

      try {
        const { data } = await request('GET', `/status/${encodeURIComponent(state.reference)}`);
        if (id !== run) return;

        if (data && (data.status === 'completed' || data.status === 'failed')) {
          applyResult(data);
          return;
        }
        if (data?.message && data.message !== state.message) set({ message: data.message });
      } catch {
        // transient network error: keep polling until maxPolls
      }

      if (id !== run) return;
      if (polls >= cfg.maxPolls) {
        set({ step: STEPS.TIMEOUT, message: 'We have not received a confirmation yet.' });
        return;
      }
      timer = setTimeout(tick, cfg.pollInterval);
    };

    timer = setTimeout(tick, immediate ? 0 : cfg.pollInterval);
  }

  const api = {
    getState: () => ({ ...state }),

    subscribe(fn) {
      listeners.add(fn);
      fn({ ...state });
      return () => listeners.delete(fn);
    },

    async loadGateways() {
      const res = await request('GET', '/gateways');
      const list = res.data?.data ?? [];
      set({ gateways: list });
      return list;
    },

    /**
     * @param {{gateway:string, productId?:string|number, productIds?:Array, msisdn?:string, amount?:number,
     *          description?:string, currency?:string, returnUrl?:string, email?:string, method?:string, meta?:object}} input
     */
    async start(input) {
      if (!input?.gateway) {
        set({ step: STEPS.FAILED, message: 'Choose a payment method.' });
        return;
      }

      stopPolling();
      set({ step: STEPS.CHARGING, busy: true, message: null, reference: null, redirectUrl: null, html: null, result: null });

      const body = {
        gateway: input.gateway,
        product_ids: input.productIds,
        product_id: input.productIds ? undefined : input.productId,
        msisdn: input.msisdn,
        amount: input.amount,
        description: input.description,
        currency: input.currency,
        return_url: input.returnUrl,
        email: input.email,
        method: input.method,
        meta: input.meta,
      };
      Object.keys(body).forEach((k) => body[k] === undefined && delete body[k]);

      try {
        const res = await request('POST', '/charge', body);
        if (res.data?.status) return applyResult(res.data);
        set({ step: STEPS.FAILED, message: failFrom(res) });
      } catch (e) {
        set({ step: STEPS.FAILED, message: e.message || 'Network error. Please try again.' });
      }
    },

    /** CPay: the customer typed the OTP from their SMS. */
    async confirmOtp(otp) {
      if (!state.reference) return;
      set({ busy: true });
      try {
        const res = await request('POST', '/confirm', { reference: state.reference, otp });
        if (res.data?.status) return applyResult(res.data);
        set({ message: failFrom(res) });
      } catch (e) {
        set({ message: e.message || 'Network error. Please try again.' });
      }
    },

    /** PayPal JS SDK flow: send the approved order id back for capture. */
    async confirmPaypal(orderId) {
      if (!state.reference) return;
      set({ busy: true });
      try {
        const res = await request('POST', '/confirm', { reference: state.reference, order_id: orderId });
        if (res.data?.status) return applyResult(res.data);
        set({ message: failFrom(res) });
      } catch (e) {
        set({ message: e.message || 'Network error. Please try again.' });
      }
    },

    /** After a redirect return (?reference=...), pick the flow back up. */
    resume(reference) {
      set({ reference, step: STEPS.POLLING, message: null });
      startPolling({ immediate: true });
    },

    /** Try again after a timeout without charging twice. */
    keepWaiting() {
      if (!state.reference) return;
      set({ step: STEPS.POLLING, message: null });
      startPolling({ immediate: true });
    },

    reset() {
      stopPolling();
      state = { ...initialState(), gateways: state.gateways };
      emit();
    },

    destroy() {
      stopPolling();
      listeners.clear();
    },
  };

  return api;
}
