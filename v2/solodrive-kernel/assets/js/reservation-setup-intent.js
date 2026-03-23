(function(){
  async function boot() {
    if (!window.Stripe || !window.SD_RESERVATION_SETUP) return;

    var form = document.getElementById('sd-setup-form');
    var mount = document.getElementById('sd-payment-element');
    var msg = document.getElementById('sd-setup-message');
    var submit = document.getElementById('sd-setup-submit');

    if (!form || !mount) return;

    var publishableKey = SD_RESERVATION_SETUP.publishableKey || '';
    var clientSecret = form.getAttribute('data-client-secret') || '';

    if (!publishableKey || !clientSecret) {
      if (msg) msg.textContent = 'Card setup is not available right now.';
      return;
    }

    var stripe = Stripe(publishableKey);
    var elements = stripe.elements({ clientSecret: clientSecret });
    var paymentElement = elements.create('payment');
    paymentElement.mount('#sd-payment-element');

    form.addEventListener('submit', async function(e){
      e.preventDefault();

      if (submit) submit.disabled = true;
      if (msg) msg.textContent = '';

      var submitResult = await elements.submit();
      if (submitResult.error) {
        if (msg) msg.textContent = submitResult.error.message || 'Unable to validate payment details.';
        if (submit) submit.disabled = false;
        return;
      }

      var result = await stripe.confirmSetup({
        elements: elements,
        clientSecret: clientSecret,
        confirmParams: {
          return_url: window.location.href
        },
        redirect: 'if_required'
      });

      if (result.error) {
        if (msg) msg.textContent = result.error.message || 'Unable to save your card.';
        if (submit) submit.disabled = false;
        return;
      }

      if (result.setupIntent && result.setupIntent.status === 'succeeded') {
        if (msg) msg.textContent = 'Card saved successfully.';
        if (submit) submit.disabled = true;
        return;
      }

      if (msg) msg.textContent = 'Card setup is processing.';
      if (submit) submit.disabled = false;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();