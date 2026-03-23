<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Places (v1.2) — CANONICAL
 *
 * Platform address primitive.
 *
 * Provides:
 * - Google Maps JS loader (once, with ready/error state)
 * - Places Autocomplete binding API
 *
 * JS API:
 *   SD_Places.bind({
 *     root,
 *     input,
 *     placeId,
 *     lat?, lng?,
 *     country?,
 *     base?
 *   })
 *
 * Canon:
 * - Bound address inputs should capture BOTH:
 *   - place_id
 *   - lat/lng
 * - place_id is preferred for route/places interoperability
 * - lat/lng is retained as stable fallback snapshot on the record
 *
 * Runtime helpers:
 *   SD_Places.isReady()
 *   SD_Places.getStatus()   // 'idle' | 'loading' | 'ready' | 'error'
 *   SD_Places.getError()
 */

final class SD_Module_Places {

  public static function register() : void {
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 5);
  }

  // ---------------------------------------------------------------------------
  // Config
  // ---------------------------------------------------------------------------

  private static function google_maps_browser_key() : string {
    $key = apply_filters('sd_google_maps_browser_key', '');

    if (!$key && defined('SD_GOOGLE_MAPS_BROWSER_KEY')) {
      $key = SD_GOOGLE_MAPS_BROWSER_KEY;
    }

    return trim((string) $key);
  }

  // ---------------------------------------------------------------------------
  // Enqueue
  // ---------------------------------------------------------------------------

  public static function enqueue() : void {

    $key = self::google_maps_browser_key();
    if ($key === '') return;

    $handle = 'sd-places';

    if (!wp_script_is($handle, 'registered')) {
      wp_register_script($handle, '', [], '1.2', true);
    }

    wp_enqueue_script($handle);

    wp_localize_script($handle, 'SD_PLACES_CFG', [
      'mapsKey' => $key,
      'debug'   => (defined('WP_DEBUG') && WP_DEBUG),
    ]);

    wp_add_inline_script($handle, self::maps_loader_js(), 'after');
    wp_add_inline_script($handle, self::places_api_js(), 'after');

    // Autocomplete dropdown always above CF7/UI layers
    wp_add_inline_style('wp-block-library', '.pac-container{z-index:999999!important;}');
  }

  // ---------------------------------------------------------------------------
  // Maps Loader
  // ---------------------------------------------------------------------------

  private static function maps_loader_js() : string {
    return <<<JS
(function(){
  if (!window.SD_PLACES_CFG || !SD_PLACES_CFG.mapsKey) return;

  window.__sdMapsStatus = window.__sdMapsStatus || 'idle';
  window.__sdMapsError  = window.__sdMapsError  || '';

  function log(){
    if (window.SD_PLACES_CFG && SD_PLACES_CFG.debug && window.console) {
      console.log.apply(console, arguments);
    }
  }

  if (window.google && google.maps && google.maps.places && google.maps.places.Autocomplete) {
    window.__sdMapsStatus = 'ready';
    log('[SD_Places] maps already ready');
    return;
  }

  if (window.__sdMapsStatus === 'loading') return;
  if (window.__sdMapsStatus === 'error') return;

  window.__sdMapsStatus = 'loading';

  window.__sdPlacesMapsInit = function(){
    window.__sdMapsStatus = 'ready';
    log('[SD_Places] maps ready');
    try {
      document.dispatchEvent(new CustomEvent('sd:maps_ready'));
    } catch(e) {}
  };

  var existing = document.querySelector('script[data-sd-maps="1"]');
  if (existing) {
    log('[SD_Places] maps script tag exists; waiting');
    return;
  }

  var src = "https://maps.googleapis.com/maps/api/js?key=" +
            encodeURIComponent(SD_PLACES_CFG.mapsKey) +
            "&libraries=places&v=weekly&callback=__sdPlacesMapsInit";

  var s = document.createElement("script");
  s.src = src;
  s.async = true;
  s.defer = true;
  s.setAttribute("data-sd-maps","1");

  s.onerror = function(){
    window.__sdMapsStatus = 'error';
    window.__sdMapsError  = 'Failed to load Google Maps JS (network, key blocked, or referrer mismatch).';
    log('[SD_Places] maps load ERROR', window.__sdMapsError);
    try {
      document.dispatchEvent(new CustomEvent('sd:maps_error', { detail: { message: window.__sdMapsError } }));
    } catch(e) {}
  };

  document.head.appendChild(s);
  log('[SD_Places] maps script appended');
})();
JS;
  }

  // ---------------------------------------------------------------------------
  // Places API
  // ---------------------------------------------------------------------------

  private static function places_api_js() : string {
    return <<<JS
(function(){
  if (window.SD_Places) return;

  function log(){
    if (window.SD_PLACES_CFG && SD_PLACES_CFG.debug && window.console) {
      console.log.apply(console, arguments);
    }
  }

  function qs(root, sel){
    return root ? root.querySelector(sel) : null;
  }

  function mapsReady(){
    return !!(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete);
  }

  function getStatus(){
    return window.__sdMapsStatus || 'idle';
  }

  function getError(){
    return window.__sdMapsError || '';
  }

  function clearField(el){
    if (el) el.value = '';
  }

  function setField(el, value){
    if (el) el.value = String(value);
  }

  function bind(cfg){
    if (!cfg) return;

    var root  = cfg.root || document;
    var input = qs(root, cfg.input);
    var pidEl = qs(root, cfg.placeId);
    var latEl = cfg.lat ? qs(root, cfg.lat) : null;
    var lngEl = cfg.lng ? qs(root, cfg.lng) : null;

    if (!input || !pidEl) {
      console.warn('[SD_Places] bind skipped: missing input or placeId field', cfg);
      return;
    }

    if (input.dataset && input.dataset.sdPlacesWired === "1") return;

    function init(){
      if (!mapsReady()) {
        if (getStatus() === 'error') {
          log('[SD_Places] cannot init autocomplete - maps error:', getError());
          return;
        }
        setTimeout(init, 200);
        return;
      }

      if (input.dataset) input.dataset.sdPlacesWired = "1";

      var opts = {
        fields: ["place_id","geometry","formatted_address","name"]
      };

      if (cfg.country) {
        opts.componentRestrictions = { country: cfg.country };
      }

      if (cfg.base && typeof cfg.base.lat === 'number' && typeof cfg.base.lng === 'number') {
        try {
          var center = new google.maps.LatLng(cfg.base.lat, cfg.base.lng);
          var radius = (cfg.base.radius_m && typeof cfg.base.radius_m === 'number') ? cfg.base.radius_m : 40000;
          var circle = new google.maps.Circle({ center: center, radius: radius });
          opts.bounds = circle.getBounds();
        } catch(e) {}
      }

      var ac = new google.maps.places.Autocomplete(input, opts);

      ac.addListener("place_changed", function(){
        var p = ac.getPlace();

        setField(pidEl, (p && p.place_id) ? p.place_id : '');

        if (p && p.formatted_address) {
          input.value = p.formatted_address;
        }

        if (p && p.geometry && p.geometry.location) {
          setField(latEl, p.geometry.location.lat());
          setField(lngEl, p.geometry.location.lng());
        } else {
          clearField(latEl);
          clearField(lngEl);
        }
      });

      input.addEventListener("input", function(){
        clearField(pidEl);
        clearField(latEl);
        clearField(lngEl);
      });

      log('[SD_Places] wired autocomplete:', cfg.input, {
        captures_place_id: !!pidEl,
        captures_lat: !!latEl,
        captures_lng: !!lngEl
      });
    }

    init();
  }

  window.SD_Places = {
    bind: bind,
    isReady: mapsReady,
    getStatus: getStatus,
    getError: getError
  };

})();
JS;
  }
}

SD_Module_Places::register();