/* laravel-payments: vanilla-JS checkout dialog for Blade / Livewire / any server-rendered page.
   Needs payments-core.global.js first. No framework, no build step. Edit freely: this file is yours once published. */
(function (global) {
  var STEPS = global.PaymentsCore.STEPS;

  function h(tag, attrs) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') node.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) node.setAttribute(k, attrs[k]);
    });
    for (var i = 2; i < arguments.length; i++) {
      var kid = arguments[i];
      if (kid) node.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid);
    }
    return node;
  }

  function mount(id, cfg) {
    var dialog = document.getElementById(id);
    var opener = document.getElementById(id + '-open');
    if (!dialog || !opener) return;

    var body = dialog.querySelector('[data-payments-body]');
    var title = dialog.querySelector('[data-payments-title]');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var flow = global.PaymentsCore.createPaymentFlow({
      baseUrl: cfg.baseUrl,
      credentials: 'same-origin',
      headers: csrf ? { 'X-CSRF-TOKEN': csrf.getAttribute('content'), 'X-Requested-With': 'XMLHttpRequest' } : {},
      pollInterval: cfg.pollInterval || 3000,
    });

    var selected = null;
    var msisdn = '';
    var otp = '';
    var lastStep = null;

    title.textContent = cfg.title || 'Pay';

    function choices(s) {
      return cfg.gateways && cfg.gateways.length
        ? s.gateways.filter(function (g) { return cfg.gateways.indexOf(g.slug) !== -1; })
        : s.gateways;
    }
    function needsPhone(s) {
      var g = s.gateways.find(function (x) { return x.slug === selected; });
      return !!g && g.driver !== 'paypal';
    }
    function fire(name, detail) {
      dialog.dispatchEvent(new CustomEvent('payments:' + name, { bubbles: true, detail: detail }));
    }
    function close() {
      flow.reset();
      if (dialog.open) dialog.close();
    }
    function message(text, cls) {
      return h('p', { 'class': 'payments-msg ' + (cls || ''), text: text });
    }

    function renderIdle(s) {
      var list = choices(s);
      if (list.length === 1 && !selected) selected = list[0].slug;
      body.replaceChildren();

      if (!list.length) return body.appendChild(message('No payment methods are available.'));

      var ul = h('div', { 'class': 'payments-gateways', role: 'radiogroup' });
      list.forEach(function (g) {
        var radio = h('input', { type: 'radio', name: id + '-gw', value: g.slug, checked: selected === g.slug ? 'checked' : null,
          onchange: function () { selected = g.slug; renderIdle(flow.getState()); } });
        ul.appendChild(h('label', { 'class': 'payments-gateway' }, radio, h('span', { text: g.name })));
      });
      body.appendChild(ul);

      var phone = null;
      if (selected && needsPhone(s)) {
        phone = h('input', { type: 'tel', 'class': 'payments-input', placeholder: 'Mobile number', value: msisdn,
          oninput: function (e) { msisdn = e.target.value; update(); } });
        body.appendChild(phone);
      }

      var pay = h('button', { type: 'button', 'class': 'payments-btn payments-btn-primary', onclick: function () {
        flow.start({
          gateway: selected,
          productIds: cfg.productIds && cfg.productIds.length ? cfg.productIds : undefined,
          msisdn: needsPhone(s) ? msisdn : undefined,
          amount: cfg.amount == null ? undefined : cfg.amount,
          description: cfg.description || undefined,
          returnUrl: cfg.returnUrl || undefined,
        });
      } });
      function update() {
        var ok = !!selected && (!needsPhone(s) || !!msisdn.trim());
        pay.disabled = !ok;
        var g = s.gateways.find(function (x) { return x.slug === selected; });
        pay.textContent = g ? 'Pay with ' + g.name : 'Choose a payment method';
      }
      update();
      body.appendChild(pay);
      if (phone) phone.focus();
    }

    function render(s) {
      if (s.step === STEPS.IDLE) {
        if (lastStep !== STEPS.IDLE || !body.firstChild) renderIdle(s);
        lastStep = s.step;
        return;
      }
      lastStep = s.step;
      body.replaceChildren();

      switch (s.step) {
        case STEPS.CHARGING:
          body.appendChild(message('Starting your payment...', 'payments-center'));
          break;
        case STEPS.POLLING:
          body.appendChild(message(s.message || 'Approve the payment on your phone.', 'payments-center'));
          body.appendChild(message('Keep this window open. We will confirm automatically.', 'payments-small'));
          body.appendChild(h('button', { type: 'button', 'class': 'payments-btn', text: 'Cancel', onclick: function () { flow.reset(); } }));
          break;
        case STEPS.OTP:
          var input = h('input', { type: 'text', inputmode: 'numeric', 'class': 'payments-input', placeholder: 'OTP',
            oninput: function (e) { otp = e.target.value; go.disabled = s.busy || !otp.trim(); },
            onkeyup: function (e) { if (e.key === 'Enter' && otp.trim()) flow.confirmOtp(otp.trim()); } });
          var go = h('button', { type: 'button', 'class': 'payments-btn payments-btn-primary', text: s.busy ? 'Confirming...' : 'Confirm',
            disabled: s.busy || !otp.trim() ? 'disabled' : null,
            onclick: function () { flow.confirmOtp(otp.trim()); } });
          input.value = otp;
          body.appendChild(message(s.message || 'Enter the code we sent to your phone.'));
          body.appendChild(input);
          body.appendChild(go);
          input.focus();
          break;
        case STEPS.REDIRECTING:
          body.appendChild(message('Taking you to complete the payment...', 'payments-center'));
          if (s.redirectUrl) body.appendChild(h('a', { 'class': 'payments-btn', href: s.redirectUrl, text: 'Continue' }));
          break;
        case STEPS.HTML:
          body.appendChild(h('iframe', { 'class': 'payments-frame', srcdoc: s.html || '',
            sandbox: 'allow-forms allow-scripts allow-same-origin allow-popups allow-top-navigation-by-user-activation' }));
          break;
        case STEPS.SUCCESS:
          body.appendChild(message('Payment complete', 'payments-center payments-ok'));
          body.appendChild(h('button', { type: 'button', 'class': 'payments-btn payments-btn-primary', text: 'Done', onclick: close }));
          fire('success', s.result);
          break;
        case STEPS.FAILED:
          body.appendChild(message(s.message || 'The payment did not go through.', 'payments-center payments-bad'));
          body.appendChild(h('button', { type: 'button', 'class': 'payments-btn', text: 'Try again', onclick: function () { flow.reset(); } }));
          body.appendChild(h('button', { type: 'button', 'class': 'payments-btn', text: 'Close', onclick: close }));
          fire('failed', s.result);
          break;
        case STEPS.TIMEOUT:
          body.appendChild(message(s.message || 'We have not received a confirmation yet.', 'payments-center'));
          body.appendChild(message('If money left your account it will still be applied once confirmed.', 'payments-small'));
          body.appendChild(h('button', { type: 'button', 'class': 'payments-btn payments-btn-primary', text: 'Keep waiting', onclick: function () { flow.keepWaiting(); } }));
          body.appendChild(h('button', { type: 'button', 'class': 'payments-btn', text: 'Close', onclick: close }));
          break;
      }
    }

    flow.subscribe(render);

    opener.addEventListener('click', function () {
      selected = null; msisdn = ''; otp = '';
      flow.reset();
      dialog.showModal();
      flow.loadGateways().then(function () { lastStep = null; render(flow.getState()); }).catch(function () { render(flow.getState()); });
    });
    dialog.querySelector('[data-payments-close]').addEventListener('click', close);
    dialog.addEventListener('close', function () { flow.reset(); });

    // Returning from PayPal / CPay card: ?reference=PAY...&status=... -> pick the payment back up.
    var ref = new URLSearchParams(global.location.search).get(cfg.resumeParam || 'reference');
    if (cfg.autoResume !== false && ref) {
      dialog.showModal();
      flow.resume(ref);
    }
  }

  global.PaymentsBlade = { mount: mount };
})(typeof window !== 'undefined' ? window : globalThis);
