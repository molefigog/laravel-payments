<!--
  Quasar payment dialog. Drop it anywhere:

    <PaymentDialog v-model="open" :product-ids="[song.id]" :headers="authHeaders" @success="onPaid" />

  Quasar components are auto-imported by the Quasar CLI. Copy and restyle freely: this file is yours.
-->
<template>
  <q-dialog :model-value="modelValue" persistent @update:model-value="onToggle">
    <q-card style="width: 420px; max-width: 94vw">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6">{{ title }}</div>
        <q-space />
        <q-btn icon="close" flat round dense :disable="state.step === STEPS.CHARGING" @click="close" />
      </q-card-section>

      <!-- choose gateway + phone -->
      <q-card-section v-if="state.step === STEPS.IDLE">
        <div v-if="!choices.length" class="text-grey-7">No payment methods are available.</div>

        <q-list v-else bordered separator class="rounded-borders">
          <q-item
            v-for="g in choices"
            :key="g.slug"
            v-ripple
            clickable
            :active="selected === g.slug"
            active-class="bg-blue-1"
            @click="selected = g.slug"
          >
            <q-item-section avatar><q-radio v-model="selected" :val="g.slug" /></q-item-section>
            <q-item-section>{{ g.name }}</q-item-section>
          </q-item>
        </q-list>

        <q-input
          v-if="selected && needsPhone"
          v-model="msisdn"
          class="q-mt-md"
          type="tel"
          label="Mobile number"
          outlined
          dense
          autofocus
          :rules="[(v) => !!v || 'Enter your mobile number']"
        />

        <q-btn
          class="full-width q-mt-md"
          color="primary"
          unelevated
          :label="payLabel"
          :disable="!canPay"
          @click="pay"
        />
      </q-card-section>

      <!-- charging -->
      <q-card-section v-else-if="state.step === STEPS.CHARGING" class="text-center q-py-lg">
        <q-spinner size="40px" color="primary" />
        <div class="q-mt-md text-grey-7">Starting your payment...</div>
      </q-card-section>

      <!-- waiting for the phone -->
      <q-card-section v-else-if="state.step === STEPS.POLLING" class="text-center q-py-lg">
        <q-spinner-dots size="48px" color="primary" />
        <div class="q-mt-md">{{ state.message || 'Approve the payment on your phone.' }}</div>
        <div class="text-caption text-grey-7 q-mt-xs">Keep this window open. We will confirm automatically.</div>
        <q-btn class="q-mt-md" flat no-caps label="Cancel" @click="cancel" />
      </q-card-section>

      <!-- OTP -->
      <q-card-section v-else-if="state.step === STEPS.OTP">
        <div>{{ state.message || 'Enter the code we sent to your phone.' }}</div>
        <q-input v-model="otp" class="q-mt-md" label="OTP" inputmode="numeric" outlined dense autofocus @keyup.enter="submitOtp" />
        <q-btn class="full-width q-mt-md" color="primary" unelevated label="Confirm" :loading="state.busy" :disable="!otp" @click="submitOtp" />
      </q-card-section>

      <!-- redirecting (PayPal) -->
      <q-card-section v-else-if="state.step === STEPS.REDIRECTING" class="text-center q-py-lg">
        <q-spinner size="40px" color="primary" />
        <div class="q-mt-md">Taking you to complete the payment...</div>
        <q-btn v-if="state.redirectUrl" class="q-mt-md" flat no-caps label="Continue" :href="state.redirectUrl" />
      </q-card-section>

      <!-- card iframe -->
      <q-card-section v-else-if="state.step === STEPS.HTML">
        <iframe
          :srcdoc="state.html"
          sandbox="allow-forms allow-scripts allow-same-origin allow-popups allow-top-navigation-by-user-activation"
          style="width: 100%; height: 420px; border: 0"
        />
      </q-card-section>

      <!-- success -->
      <q-card-section v-else-if="state.step === STEPS.SUCCESS" class="text-center q-py-lg">
        <q-icon name="check_circle" color="positive" size="64px" />
        <div class="text-h6 q-mt-sm">Payment complete</div>
        <q-btn class="q-mt-md" color="primary" unelevated label="Done" @click="close" />
      </q-card-section>

      <!-- failed -->
      <q-card-section v-else-if="state.step === STEPS.FAILED" class="text-center q-py-lg">
        <q-icon name="error" color="negative" size="64px" />
        <div class="text-subtitle1 q-mt-sm">{{ state.message || 'The payment did not go through.' }}</div>
        <div class="q-mt-md">
          <q-btn flat no-caps label="Try again" @click="reset" />
          <q-btn flat no-caps label="Close" @click="close" />
        </div>
      </q-card-section>

      <!-- timeout -->
      <q-card-section v-else-if="state.step === STEPS.TIMEOUT" class="text-center q-py-lg">
        <q-icon name="schedule" color="warning" size="64px" />
        <div class="q-mt-sm">{{ state.message }}</div>
        <div class="text-caption text-grey-7">If money left your account it will still be applied once confirmed.</div>
        <div class="q-mt-md">
          <q-btn color="primary" unelevated no-caps label="Keep waiting" @click="keepWaiting" />
          <q-btn flat no-caps label="Close" @click="close" />
        </div>
      </q-card-section>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { usePayment } from './usePayment.js';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: 'Pay' },
  productIds: { type: Array, default: () => [] },
  amount: { type: Number, default: undefined }, // only when the server has no ProductResolver
  description: { type: String, default: undefined },
  currency: { type: String, default: undefined },
  gateways: { type: Array, default: () => [] }, // limit to these slugs; empty = all
  defaultMsisdn: { type: String, default: '' },
  returnUrl: { type: String, default: undefined },
  email: { type: String, default: undefined },
  baseUrl: { type: String, default: '/api/payments' },
  headers: { type: [Object, Function], default: () => ({}) },
  credentials: { type: String, default: 'same-origin' },
  pollInterval: { type: Number, default: 3000 },
  resumeReference: { type: String, default: undefined }, // pass ?reference=... after a redirect return
});

const emit = defineEmits(['update:modelValue', 'success', 'failed']);

const { state, STEPS, loadGateways, start, confirmOtp, keepWaiting, resume, reset, flow } = usePayment({
  baseUrl: props.baseUrl,
  headers: props.headers,
  credentials: props.credentials,
  pollInterval: props.pollInterval,
});

const selected = ref(null);
const msisdn = ref(props.defaultMsisdn);
const otp = ref('');

const choices = computed(() =>
  props.gateways.length ? state.gateways.filter((g) => props.gateways.includes(g.slug)) : state.gateways
);
const current = computed(() => state.gateways.find((g) => g.slug === selected.value));
const needsPhone = computed(() => current.value && current.value.driver !== 'paypal');
const canPay = computed(() => !!selected.value && (!needsPhone.value || !!msisdn.value));
const payLabel = computed(() => (current.value ? `Pay with ${current.value.name}` : 'Choose a payment method'));

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return;
    otp.value = '';
    try {
      await loadGateways();
    } catch {
      /* the idle step shows "no payment methods" */
    }
    if (choices.value.length === 1) selected.value = choices.value[0].slug;
    if (props.resumeReference) resume(props.resumeReference);
  },
  { immediate: true }
);

watch(
  () => state.step,
  (step) => {
    if (step === STEPS.SUCCESS) emit('success', state.result);
    if (step === STEPS.FAILED) emit('failed', state.result);
  }
);

function pay() {
  return start({
    gateway: selected.value,
    productIds: props.productIds.length ? props.productIds : undefined,
    msisdn: needsPhone.value ? msisdn.value : undefined,
    amount: props.amount,
    description: props.description,
    currency: props.currency,
    returnUrl: props.returnUrl,
    email: props.email,
  });
}

function submitOtp() {
  if (otp.value) confirmOtp(otp.value);
}

function cancel() {
  reset();
}

function close() {
  flow.reset(); // stops polling; the server keeps processing the payment either way
  emit('update:modelValue', false);
}

function onToggle(value) {
  if (!value) close();
}
</script>
