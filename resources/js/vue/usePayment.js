import { computed, getCurrentInstance, onBeforeUnmount, reactive } from 'vue';
import { createPaymentFlow, STEPS } from '../core/payments-core.js';

/**
 * Vue 3 composable over the headless payment flow.
 *
 *   const { state, start, confirmOtp } = usePayment({ baseUrl: '/api/payments', headers: () => ({ Authorization: `Bearer ${token}` }) });
 *   await start({ gateway: 'mpesa', productIds: [12], msisdn: '58123456' });
 *   state.step   // 'polling' ... 'success'
 */
export function usePayment(options = {}) {
  const flow = createPaymentFlow(options);
  const state = reactive(flow.getState());
  const off = flow.subscribe((s) => Object.assign(state, s));

  if (getCurrentInstance()) {
    onBeforeUnmount(() => {
      off();
      flow.destroy();
    });
  }

  const finished = computed(() => [STEPS.SUCCESS, STEPS.FAILED, STEPS.TIMEOUT].includes(state.step));

  return {
    state,
    STEPS,
    finished,
    flow,
    loadGateways: flow.loadGateways,
    start: flow.start,
    confirmOtp: flow.confirmOtp,
    confirmPaypal: flow.confirmPaypal,
    resume: flow.resume,
    keepWaiting: flow.keepWaiting,
    reset: flow.reset,
  };
}
