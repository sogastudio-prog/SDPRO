(function(){
  function safeGet(obj, path) {
    try {
      return path.split('.').reduce(function(acc, k){ return acc && acc[k]; }, obj);
    } catch(e) { return null; }
  }

  function onReady(fn){
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function findField(names) {
    for (var i = 0; i < names.length; i++) {
      var el = document.querySelector('[name="' + names[i] + '"]');
      if (el) return el;
    }
    return null;
  }

  // ---------------------------------------------------------------------------
  // CF7 redirect handler:
  // Server module injects { redirect_url } into apiResponse.
  // ---------------------------------------------------------------------------
  function bindCf7Redirect(){
    document.addEventListener('wpcf7mailsent', function(e){
      var url = safeGet(e, 'detail.apiResponse.redirect_url');
      if (url && typeof url === 'string') {
        window.location.href = url;
      }
    }, false);
  }

  // ---------------------------------------------------------------------------
  // Google Places Autocomplete glue:
  // Expects inputs:
  //  - pickup_address (text) + pickup_place_id (hidden)
  //  - dropoff_address (text) + dropoff_place_id (hidden)
  // ---------------------------------------------------------------------------
  function bindPlaces(){
    if (!window.google || !google.maps || !google.maps.places) return;

    function wire(addressName, placeIdName){
      var addr = document.querySelector('[name="' + addressName + '"]');
      var pid  = document.querySelector('[name="' + placeIdName + '"]');
      if (!addr || !pid) return;

      if (addr.dataset.sdPlacesBound === '1') return;
      addr.dataset.sdPlacesBound = '1';

      var ac = new google.maps.places.Autocomplete(addr, {
        fields: ['place_id', 'formatted_address']
      });

      ac.addListener('place_changed', function(){
        var place = ac.getPlace();
        if (place && place.place_id) {
          pid.value = place.place_id;
        }
      });

      addr.addEventListener('input', function(){
        pid.value = '';
      });
    }

    wire('pickup_address', 'pickup_place_id');
    wire('dropoff_address', 'dropoff_place_id');
  }

  // ---------------------------------------------------------------------------
  // ASAP / RESERVE mode toggle
  // ---------------------------------------------------------------------------
  function bindRequestModeToggle(){
    var asapBtn = document.getElementById('sd-mode-asap');
    var reserveBtn = document.getElementById('sd-mode-reserve');
    var reserveWrap = document.getElementById('sd-reserve-fields');

    var modeInput = findField(['sd_request_mode', 'sd-request-mode']);
    var reserveDate = findField(['reserve_date', 'reserve-date']);
    var reserveTime = findField(['reserve_time', 'reserve-time']);
    var submitBtn = document.querySelector('.wpcf7 input[type="submit"], .wpcf7 button[type="submit"], input[type="submit"], button[type="submit"]');

    if (!asapBtn || !reserveBtn || !reserveWrap || !modeInput) return;

    function setSubmitText(text) {
      if (!submitBtn) return;
      if (submitBtn.tagName === 'BUTTON') {
        submitBtn.textContent = text;
      } else {
        submitBtn.value = text;
      }
    }

    function setMode(mode){
      var isReserve = (String(mode).toUpperCase() === 'RESERVE');

      modeInput.value = isReserve ? 'RESERVE' : 'ASAP';
      reserveWrap.style.display = isReserve ? '' : 'none';

      asapBtn.classList.toggle('is-active', !isReserve);
      reserveBtn.classList.toggle('is-active', isReserve);

      asapBtn.setAttribute('aria-pressed', !isReserve ? 'true' : 'false');
      reserveBtn.setAttribute('aria-pressed', isReserve ? 'true' : 'false');

      if (reserveDate) {
        reserveDate.required = isReserve;
        reserveDate.setAttribute('aria-required', isReserve ? 'true' : 'false');
      }

      if (reserveTime) {
        reserveTime.required = isReserve;
        reserveTime.setAttribute('aria-required', isReserve ? 'true' : 'false');
      }

      setSubmitText(isReserve ? 'Reserve ride' : 'Continue');
    }

    if (asapBtn.dataset.sdModeBound !== '1') {
      asapBtn.dataset.sdModeBound = '1';
      asapBtn.addEventListener('click', function(e){
        e.preventDefault();
        setMode('ASAP');
      });
    }

    if (reserveBtn.dataset.sdModeBound !== '1') {
      reserveBtn.dataset.sdModeBound = '1';
      reserveBtn.addEventListener('click', function(e){
        e.preventDefault();
        setMode('RESERVE');

        if (reserveDate) {
          window.setTimeout(function(){
            try { reserveDate.focus(); } catch(err) {}
          }, 50);
        }
      });
    }

    setMode(modeInput.value === 'RESERVE' ? 'RESERVE' : 'ASAP');
  }

  function initRequestSurface(){
    bindRequestModeToggle();

    var tries = 0;
    var t = setInterval(function(){
      tries++;
      if (window.google && google.maps && google.maps.places) {
        clearInterval(t);
        bindPlaces();
      }
      if (tries >= 30) clearInterval(t);
    }, 250);
  }

  onReady(function(){
    bindCf7Redirect();
    initRequestSurface();
  });

  // Re-bind after CF7 invalid/spam/mailfailed responses in case CF7 redraws the form.
  document.addEventListener('wpcf7invalid', initRequestSurface, false);
  document.addEventListener('wpcf7spam', initRequestSurface, false);
  document.addEventListener('wpcf7mailfailed', initRequestSurface, false);
})();