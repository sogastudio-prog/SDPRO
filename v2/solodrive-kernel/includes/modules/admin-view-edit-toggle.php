<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Admin_ViewEditToggle (v1)
 *
 * Purpose:
 * - Standardize the “read-only by default → click Edit to reveal inputs” pattern
 *   for admin metaboxes.
 *
 * What it does:
 * - Renders a wrapper with:
 *   - Read-only region
 *   - Edit region (hidden by default)
 *   - Edit + Cancel controls
 * - Enqueues a tiny JS/CSS (once) to toggle modes.
 *
 * What it DOES NOT do:
 * - It does not save anything.
 * - It does not manage nonces/caps. Your module still handles save_post_*.
 *
 * Usage:
 *   SD_Admin_ViewEditToggle::render('sd_tenant_settings', function(){ ... }, function(){ ... });
 */
final class SD_Admin_ViewEditToggle {

  private static bool $assets_hooked = false;

  /**
   * Render a view/edit toggle wrapper.
   *
   * @param string   $id            Unique id within the page (used for DOM + data attrs).
   * @param callable $render_view   Callback to echo the read-only UI.
   * @param callable $render_edit   Callback to echo the editable UI (inputs).
   * @param array    $opts          Optional:
   *   - edit_label   (string) default "Edit"
   *   - cancel_label (string) default "Cancel"
   *   - start_in_edit (bool) default false (supports ?sd_edit=1 later if you want)
   */
  public static function render(string $id, callable $render_view, callable $render_edit, array $opts = []) : void {
    self::ensure_assets();

    $edit_label    = isset($opts['edit_label']) ? (string) $opts['edit_label'] : 'Edit';
    $cancel_label  = isset($opts['cancel_label']) ? (string) $opts['cancel_label'] : 'Cancel';
    $start_in_edit = !empty($opts['start_in_edit']);

    $wrap_id = 'sd-ve-' . sanitize_key($id);
    $mode    = $start_in_edit ? 'edit' : 'view';

    echo '<div id="' . esc_attr($wrap_id) . '" class="sd-admin-ve" data-sd-ve="' . esc_attr($wrap_id) . '" data-mode="' . esc_attr($mode) . '">';

      // View region
      echo '<div class="sd-admin-ve__view">';
        call_user_func($render_view);
        echo '<p class="sd-admin-ve__actions">';
          echo '<button type="button" class="button button-primary sd-admin-ve__btn-edit">' . esc_html($edit_label) . '</button>';
        echo '</p>';
      echo '</div>';

      // Edit region
      echo '<div class="sd-admin-ve__edit" style="display:none;">';
        call_user_func($render_edit);
        echo '<p class="sd-admin-ve__actions">';
          echo '<button type="button" class="button sd-admin-ve__btn-cancel">' . esc_html($cancel_label) . '</button> ';
          echo '<span class="description">Use the normal “Update” button to save.</span>';
        echo '</p>';
      echo '</div>';

    echo '</div>';
  }

  // ---------------------------------------------------------------------------
  // Assets
  // ---------------------------------------------------------------------------

  private static function ensure_assets() : void {
    if (self::$assets_hooked) return;
    self::$assets_hooked = true;

    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
  }

  public static function enqueue_assets() : void {
    // Only load on post edit screens (metabox context).
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || empty($screen->base)) return;
    if (!in_array($screen->base, ['post', 'post-new'], true)) return;

    $js_handle  = 'sd-admin-view-edit-toggle';
    $css_handle = 'sd-admin-view-edit-toggle-css';

    if (!wp_script_is($js_handle, 'registered')) {
      wp_register_script($js_handle, '', [], '1.0.0', true);
    }
    wp_enqueue_script($js_handle);
    wp_add_inline_script($js_handle, self::inline_js(), 'after');

    if (!wp_style_is($css_handle, 'registered')) {
      wp_register_style($css_handle, false, [], '1.0.0');
    }
    wp_enqueue_style($css_handle);
    wp_add_inline_style($css_handle, self::inline_css());
  }

  private static function inline_js() : string {
    return <<<JS
(function(){
  function closest(el, sel){
    while (el && el.nodeType === 1) {
      if (el.matches(sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  function setMode(wrap, mode){
    if (!wrap) return;
    var view = wrap.querySelector('.sd-admin-ve__view');
    var edit = wrap.querySelector('.sd-admin-ve__edit');
    if (!view || !edit) return;

    if (mode === 'edit') {
      view.style.display = 'none';
      edit.style.display = 'block';
      wrap.classList.add('sd-admin-ve--editing');
      wrap.dataset.mode = 'edit';
    } else {
      edit.style.display = 'none';
      view.style.display = 'block';
      wrap.classList.remove('sd-admin-ve--editing');
      wrap.dataset.mode = 'view';
    }
  }

  document.addEventListener('click', function(e){
    var btnEdit = closest(e.target, '.sd-admin-ve__btn-edit');
    if (btnEdit) {
      var wrap = closest(btnEdit, '.sd-admin-ve');
      setMode(wrap, 'edit');
      e.preventDefault();
      return;
    }

    var btnCancel = closest(e.target, '.sd-admin-ve__btn-cancel');
    if (btnCancel) {
      var wrap2 = closest(btnCancel, '.sd-admin-ve');
      setMode(wrap2, 'view');
      e.preventDefault();
      return;
    }
  });

  // Initialize: honor data-mode if you ever decide to start in edit mode.
  document.addEventListener('DOMContentLoaded', function(){
    var wraps = document.querySelectorAll('.sd-admin-ve');
    for (var i=0; i<wraps.length; i++){
      var w = wraps[i];
      setMode(w, (w.dataset.mode === 'edit') ? 'edit' : 'view');
    }
  });
})();
JS;
  }

  private static function inline_css() : string {
    return <<<CSS
.sd-admin-ve__actions { margin: 10px 0 0 0; }
.sd-admin-ve--editing {
  border-left: 4px solid #2271b1;
  padding-left: 10px;
  background: #f6fbff;
}
.sd-admin-ve__view table.widefat {
  margin-top: 6px;
}
CSS;
  }
}