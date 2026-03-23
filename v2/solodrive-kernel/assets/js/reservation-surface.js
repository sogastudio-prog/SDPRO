(function(){
  function bindPlacesWhenReady() {
    if (!window.SD_Places || !SD_Places.bind) return false;

    SD_Places.bind({
      input: '#sd_res_pickup_address',
      placeId: '#sd_res_pickup_place_id'
    });

    SD_Places.bind({
      input: '#sd_res_dropoff_address',
      placeId: '#sd_res_dropoff_place_id'
    });

    return true;
  }

  function boot() {
    var tries = 0;
    var t = setInterval(function(){
      tries++;
      if (bindPlacesWhenReady()) {
        clearInterval(t);
      }
      if (tries > 60) {
        clearInterval(t);
      }
    }, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();