<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_OperatorSettingsProfile {

  public static function render(int $tenant_id) : string {

    $values = class_exists('SD_TenantConfig', false)
      ? SD_TenantConfig::all($tenant_id)
      : [];

    $errors  = [];
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_sd_profile_nonce'])) {

      if (!wp_verify_nonce($_POST['_sd_profile_nonce'], 'sd_profile_save')) {
        $errors[] = 'Security check failed.';
      } else {

        $business_name = isset($_POST['profile_business_name'])
          ? sanitize_text_field((string) $_POST['profile_business_name'])
          : '';

        $support_phone = isset($_POST['support_phone'])
          ? sanitize_text_field((string) $_POST['support_phone'])
          : '';

        $support_email = isset($_POST['support_email'])
          ? sanitize_email((string) $_POST['support_email'])
          : '';

        $description = isset($_POST['profile_description'])
          ? sanitize_textarea_field((string) $_POST['profile_description'])
          : '';

        if ($business_name === '') {
          $errors['profile_business_name'] = 'Business name is required.';
        }

        if (empty($errors)) {

          SD_TenantConfig::set_value($tenant_id, SD_Meta::PROFILE_BUSINESS_NAME, $business_name);
          SD_TenantConfig::set_value($tenant_id, SD_Meta::PROFILE_SUPPORT_PHONE, $support_phone);
          SD_TenantConfig::set_value($tenant_id, SD_Meta::PROFILE_SUPPORT_EMAIL, $support_email);
          SD_TenantConfig::set_value($tenant_id, SD_Meta::PROFILE_DESCRIPTION, $description);

          $values = SD_TenantConfig::all($tenant_id);
          $success = true;
        }
      }
    }

    ob_start();

    echo '<div class="sd-operator-section-card">';

      echo '<div class="sd-operator-section-top">';
        echo '<div>';
          echo '<h2 class="sd-operator-section-title">Tenant Profile</h2>';
          echo '<div class="sd-operator-section-desc">Information shown to riders on the trip page.</div>';
        echo '</div>';
        echo '<a class="sd-operator-back" href="' . esc_url(home_url('/operator/')) . '">← Back</a>';
      echo '</div>';

      if ($success) {
        echo '<div class="sd-operator-notice sd-operator-notice--success">Profile updated.</div>';
      }

      if (!empty($errors) && is_array($errors)) {
        echo '<div class="sd-operator-notice sd-operator-notice--error">Please fix the highlighted fields.</div>';
      }

      echo '<form method="post">';
      wp_nonce_field('sd_profile_save', '_sd_profile_nonce');

      echo '<div class="sd-operator-form-grid">';

        // Business name (REQUIRED)
        echo '<div class="sd-operator-field sd-operator-field--full">';
          echo '<label class="sd-operator-label">Business Name *</label>';
          echo '<input type="text" name="profile_business_name" value="' . esc_attr($values[SD_Meta::PROFILE_BUSINESS_NAME] ?? '') . '">';
          echo '<div class="sd-operator-help">Displayed to riders and customers.</div>';
          if (isset($errors['profile_business_name'])) {
            echo '<div class="sd-operator-error">' . esc_html($errors['profile_business_name']) . '</div>';
          }
        echo '</div>';

        // Support phone
        echo '<div class="sd-operator-field">';
          echo '<label class="sd-operator-label">Support Phone</label>';
          echo '<input type="text" name="support_phone" value="' . esc_attr($values[SD_Meta::PROFILE_SUPPORT_PHONE] ?? '') . '">';
        echo '</div>';

        // Support email
        echo '<div class="sd-operator-field">';
          echo '<label class="sd-operator-label">Support Email</label>';
          echo '<input type="text" name="support_email" value="' . esc_attr($values[SD_Meta::PROFILE_SUPPORT_EMAIL] ?? '') . '">';
        echo '</div>';

        // Description
        echo '<div class="sd-operator-field sd-operator-field--full">';
          echo '<label class="sd-operator-label">Business Description</label>';
          echo '<textarea name="profile_description">' . esc_textarea($values[SD_Meta::PROFILE_DESCRIPTION] ?? '') . '</textarea>';
        echo '</div>';

      echo '</div>';

      echo '<div class="sd-operator-actions-row">';
        echo '<button type="submit" class="sd-operator-btn">Save Profile</button>';
        echo '<a href="' . esc_url(home_url('/operator/')) . '" class="sd-operator-btn sd-operator-btn--ghost">Cancel</a>';
      echo '</div>';

      echo '</form>';

    echo '</div>';

    return (string) ob_get_clean();
  }
}