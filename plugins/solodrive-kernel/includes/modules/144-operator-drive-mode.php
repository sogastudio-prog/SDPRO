<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorDriveMode
 *
 * Drive tab renderer for the unified operator application shell.
 *
 * Canon:
 * - lead is the queue and trip-ops selection root
 * - quote/auth/ride hydrate under lead
 * - ride appears only after successful authorization
 *
 * Important:
 * - This module no longer owns page routing
 * - This module renders Drive tab content only
 * - Drive-only assets/runtime boot only when ?tab=drive
 *
 * Fixes included:
 * - Uses SD_Module_OperatorLocation::get_context() instead of nonexistent get_operator_context()
 * - Renders location controls so live location can actually be refreshed
 */

if (class_exists('SD_Module_OperatorDriveMode', false)) { return; }

final class SD_Module_OperatorDriveMode {

  private const QUEUE_LIMIT = 7;
  private static $drive_runtime_booted = false;

  public static function register() : void {
    add_shortcode('sd_operator_drive_mode', [__CLASS__, 'shortcode']);
  }

  public static function boot_drive_runtime(int $tenant_id = 0) : void {
    if (self::$drive_runtime_booted) {
      return;
    }
    self::$drive_runtime_booted = true;

    $tenant_id = absint($tenant_id);

    self::maybe_boot_module('SD_Module_OperatorPushApi');
    self::maybe_boot_module('SD_Module_OperatorPushKeys');
    self::maybe_boot_module('SD_Module_OperatorPWA');
    self::maybe_boot_module('SD_Module_OperatorNotificationService');
    self::maybe_boot_module('SD_Module_OperatorLocation');
    self::maybe_boot_module('SD_Module_OperatorLocationResolver');

    add_action('wp_enqueue_scripts', function() use ($tenant_id) {
      self::maybe_enqueue_module_assets('SD_Module_OperatorPWA', $tenant_id);
      self::maybe_enqueue_module_assets('SD_Module_OperatorPushApi', $tenant_id);
      self::maybe_enqueue_module_assets('SD_Module_OperatorPushKeys', $tenant_id);
      self::maybe_enqueue_module_assets('SD_Module_OperatorLocation', $tenant_id);
      self::maybe_enqueue_module_assets('SD_Module_OperatorLocationResolver', $tenant_id);
    }, 20);
  }

  public static function shortcode() : string {
    if (!is_user_logged_in()) {
      return '<div class="sd-op-wrap"><div class="sd-op-card"><p>Please log in.</p></div></div>';
    }

    $tenant_id = self::current_user_tenant_id();
    if ($tenant_id <= 0) {
      return '<div class="sd-op-wrap"><div class="sd-op-card"><p>No tenant assigned.</p></div></div>';
    }

    self::boot_drive_runtime($tenant_id);

    return self::render_tab($tenant_id);
  }

  public static function render_tab(int $tenant_id) : string {
    $tenant_id = absint($tenant_id);

    self::boot_drive_runtime($tenant_id);

    // FIX: use get_context() from 142-operator-location.php
    $operator = class_exists('SD_Module_OperatorLocation', false) && method_exists('SD_Module_OperatorLocation', 'get_context')
      ? SD_Module_OperatorLocation::get_context(get_current_user_id())
      : [
          'status'              => 'offline',
          'status_label'        => 'OFFLINE',
          'live_location_label' => 'missing',
          'base_location_label' => 'missing',
          'last_lat'            => 0.0,
          'last_lng'            => 0.0,
          'last_ts'             => 0,
        ];

    $queue_items = class_exists('SD_Module_OperatorQueue', false) && method_exists('SD_Module_OperatorQueue', 'get_queue')
      ? SD_Module_OperatorQueue::get_queue($tenant_id, self::QUEUE_LIMIT)
      : [];

    $selected_lead_id = class_exists('SD_Module_OperatorQueue', false) && method_exists('SD_Module_OperatorQueue', 'resolve_selected_lead_id')
      ? SD_Module_OperatorQueue::resolve_selected_lead_id($queue_items)
      : self::resolve_selected_lead_id_fallback();

    $active = class_exists('SD_Module_OperatorActiveRide', false) && method_exists('SD_Module_OperatorActiveRide', 'build')
      ? SD_Module_OperatorActiveRide::build($selected_lead_id, $tenant_id)
      : [];

    $active_view = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : 'queue';
    if (!in_array($active_view, ['queue', 'trip-ops'], true)) {
      $active_view = 'queue';
    }

    $waiting_quotes_count = 0;
    foreach ($queue_items as $item) {
      if ((string) ($item['bucket'] ?? '') === 'quotes_waiting') {
        $waiting_quotes_count++;
      }
    }

    $base_url = self::operator_app_url();

    ob_start();

    echo '<div class="sd-op-wrap">';
    echo '  <div class="sd-op-head">';
    echo '    <div>';
    echo '      <div class="sd-op-kicker">Drive Mode</div>';
    echo '      <h1>' . esc_html(wp_get_current_user()->display_name ?: 'Operator') . '</h1>';
    echo '      <div class="sd-op-sub">Tenant #' . (int) $tenant_id . '</div>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="sd-op-pwa-actions">';
    echo '    <button type="button" class="sd-op-btn" id="sd-install-pwa-btn">Install app</button>';
    echo '    <button type="button" class="sd-op-btn" id="sd-enable-alerts-btn">Enable alerts</button>';
    echo '    <button type="button" class="sd-op-btn" id="sd-test-alert-btn">Send test alert</button>';

    // FIX: render location controls so live location can be refreshed
    if (class_exists('SD_Module_OperatorLocation', false) && method_exists('SD_Module_OperatorLocation', 'render_update_location_button')) {
      echo SD_Module_OperatorLocation::render_update_location_button([
        'label' => 'Update location',
      ]);
    }

    if (class_exists('SD_Module_OperatorLocation', false) && method_exists('SD_Module_OperatorLocation', 'render_status_toggle_button')) {
      echo SD_Module_OperatorLocation::render_status_toggle_button();
    }

    echo '  </div>';

    echo '  <div class="sd-op-strip">';
    echo '    <span><strong>Live location:</strong> ' . esc_html((string) ($operator['live_location_label'] ?? 'missing')) . '</span>';
    echo '    <span><strong>Base location:</strong> ' . esc_html((string) ($operator['base_location_label'] ?? 'missing')) . '</span>';
    echo '  </div>';

    echo '  <div class="sd-op-strip" style="opacity:.8;font-size:13px">';
    echo '    <span><strong>PWA config:</strong> <span id="sd-debug-pwa-config">checking</span></span>';
    echo '    <span><strong>PWA JS:</strong> <span id="sd-debug-pwa-js">checking</span></span>';
    echo '    <span><strong>Push JS:</strong> <span id="sd-debug-push-js">checking</span></span>';
    echo '    <span><strong>SW:</strong> <span id="sd-debug-sw">checking</span></span>';
    echo '  </div>';

    echo '  <div class="sd-op-strip" style="font-size:13px">';
    echo '    <span><strong>Install:</strong> <span id="sd-debug-install-state">checking</span></span>';
    echo '    <span><strong>Alerts:</strong> <span id="sd-debug-push-state">checking</span></span>';
    echo '    <span><strong>Monitor:</strong> <span id="sd-monitor-state">Starting</span></span>';
    echo '  </div>';

    echo '  <div class="sd-op-card" id="sd-op-action-card" style="margin-top:12px;padding:14px 16px;">';
    echo '    <strong>Action status:</strong> <span id="sd-op-action-status">Ready.</span>';
    echo '  </div>';

    echo '  <div class="sd-op-card" id="sd-op-monitor-card" style="margin-top:12px;padding:14px 16px;">';
    echo '    <strong>Live monitor:</strong> <span id="sd-monitor-message">Foreground queue monitoring enabled for this page.</span>';
    echo '  </div>';

    $queue_btn_classes = 'sd-op-toggle';
    if ($active_view === 'queue') {
      $queue_btn_classes .= ' is-active';
    }
    if ($waiting_quotes_count > 0) {
      $queue_btn_classes .= ' is-alert';
    }

    $trip_btn_classes = 'sd-op-toggle';
    if ($active_view === 'trip-ops') {
      $trip_btn_classes .= ' is-active';
    }

    echo '  <div class="sd-op-toggles">';
    echo '    <a id="sd-queue-toggle" class="' . esc_attr($queue_btn_classes) . '" href="' . esc_url(add_query_arg([
      'tab'  => 'drive',
      'view' => 'queue',
    ], $base_url)) . '">';
    echo '      Queue (<span id="sd-queue-count">' . (int) count($queue_items) . '</span>)';
    if ($waiting_quotes_count > 0) {
      echo ' <span class="sd-op-badge" id="sd-queue-waiting-badge">' . (int) $waiting_quotes_count . ' quote' . ($waiting_quotes_count === 1 ? '' : 's') . '</span>';
    } else {
      echo ' <span class="sd-op-badge" id="sd-queue-waiting-badge" style="display:none"></span>';
    }
    echo '    </a>';

    echo '    <a class="' . esc_attr($trip_btn_classes) . '" href="' . esc_url(add_query_arg([
      'tab'     => 'drive',
      'view'    => 'trip-ops',
      'lead_id' => $selected_lead_id,
    ], $base_url)) . '">trip-ops</a>';
    echo '  </div>';

    echo '  <div class="sd-op-lower">';
    if ($active_view === 'queue') {
      echo '    <div id="sd-op-queue-panel">';
      echo         self::render_queue_panel($queue_items, $selected_lead_id, $base_url);
      echo '    </div>';
    } else {
      if (class_exists('SD_Module_OperatorTripOps', false) && method_exists('SD_Module_OperatorTripOps', 'render_active_ride_panel')) {
        echo SD_Module_OperatorTripOps::render_active_ride_panel($active);
      } else {
        echo '<div class="sd-op-card"><p>trip-ops module unavailable.</p></div>';
      }
    }
    echo '  </div>';
    echo '</div>';

    echo self::boot_script($tenant_id, $active_view, $base_url);

    return (string) ob_get_clean();
  }

  private static function maybe_boot_module(string $class_name) : void {
    if (!class_exists($class_name, false)) {
      return;
    }

    if (method_exists($class_name, 'register')) {
      $class_name::register();
      return;
    }

    if (method_exists($class_name, 'boot')) {
      $class_name::boot();
      return;
    }

    if (method_exists($class_name, 'init')) {
      $class_name::init();
      return;
    }
  }

  private static function maybe_enqueue_module_assets(string $class_name, int $tenant_id = 0) : void {
    if (!class_exists($class_name, false)) {
      return;
    }

    foreach (['enqueue_assets', 'enqueue', 'enqueue_frontend', 'maybe_enqueue_assets', 'maybe_enqueue_frontend'] as $method) {
      if (!method_exists($class_name, $method)) {
        continue;
      }

      $ref = new ReflectionMethod($class_name, $method);
      $argc = $ref->getNumberOfParameters();

      if ($argc >= 1) {
        $class_name::$method($tenant_id);
      } else {
        $class_name::$method();
      }
      return;
    }
  }

  private static function boot_script(int $tenant_id, string $active_view, string $base_url) : string {
    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce('sd_operator_queue');

    return '<script>
    (function(){
      var CFG = {
        tenantId: ' . (int) $tenant_id . ',
        activeView: ' . wp_json_encode($active_view) . ',
        ajaxUrl: ' . wp_json_encode($ajax_url) . ',
        nonce: ' . wp_json_encode($nonce) . ',
        queueLimit: ' . (int) self::QUEUE_LIMIT . ',
        baseUrl: ' . wp_json_encode($base_url) . ',
        pollMsVisible: 8000,
        pollMsHidden: 20000
      };

      var state = {
        lastSignature: "",
        lastCount: null,
        lastWaitingQuotes: null,
        alertSoundUnlocked: false,
        pollTimer: null,
        firstSnapshotSeen: false
      };

      function setText(id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
      }

      function escapeHtml(s) {
        return String(s == null ? "" : s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/\'/g, "&#039;");
      }

      function installLabel(stateName) {
        switch (stateName) {
          case "available": return "Install available";
          case "installed": return "Installed";
          case "accepted": return "Accepted";
          case "browser": return "Browser tab";
          default: return stateName || "unknown";
        }
      }

      function pushLabel(stateName) {
        switch (stateName) {
          case "subscribed": return "Subscribed";
          case "idle": return "Idle";
          case "denied": return "Blocked";
          case "missing-vapid": return "Missing VAPID";
          case "granted-no-subscription": return "Granted, not subscribed";
          case "no-service-worker": return "No service worker";
          case "no-push-manager": return "No PushManager";
          case "no-notification-api": return "No Notification API";
          case "permission-not-granted": return "Permission not granted";
          case "requires-user-gesture": return "Needs tap";
          case "server-save-failed": return "Server save failed";
          case "subscribe-failed": return "Subscribe failed";
          case "sw-not-ready": return "SW not ready";
          default: return stateName || "unknown";
        }
      }

      function refreshDebug() {
        setText("sd-debug-pwa-config", window.__sdOperatorPwaLocalized ? "present" : "missing");
        setText("sd-debug-pwa-js", window.SDOperatorPWA ? "loaded" : "missing");
        setText("sd-debug-push-js", window.SDOperatorPush ? "loaded" : "missing");
        setText("sd-debug-sw", document.documentElement.getAttribute("data-sd-sw") || "unknown");
        setText("sd-debug-install-state", installLabel(document.documentElement.getAttribute("data-sd-install")));
        setText("sd-debug-push-state", pushLabel(document.documentElement.getAttribute("data-sd-push")));
      }

      function setActionStatus(msg) {
        setText("sd-op-action-status", msg || "Ready.");
      }

      function setMonitorState(msg) {
        setText("sd-monitor-state", msg || "Ready");
      }

      function setMonitorMessage(msg) {
        setText("sd-monitor-message", msg || "Foreground queue monitoring enabled for this page.");
      }

      function maybeUnlockAudio() {
        state.alertSoundUnlocked = true;
      }

      function pulseAlertUI(on) {
        var card = document.getElementById("sd-op-action-card");
        var toggle = document.getElementById("sd-queue-toggle");

        if (card) {
          card.style.borderColor = on ? "#fecaca" : "";
          card.style.background = on ? "#fef2f2" : "";
        }

        if (toggle) {
          if (on) toggle.classList.add("is-alert");
          else toggle.classList.remove("is-alert");
        }
      }

      function tryVibrate() {
        if (navigator.vibrate) {
          navigator.vibrate([160, 100, 160]);
        }
      }

      function tryBeep() {
        if (!state.alertSoundUnlocked) return;

        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;

        try {
          var ctx = new AC();
          var osc = ctx.createOscillator();
          var gain = ctx.createGain();

          osc.type = "sine";
          osc.frequency.value = 880;
          gain.gain.value = 0.02;

          osc.connect(gain);
          gain.connect(ctx.destination);

          osc.start();
          window.setTimeout(function(){
            osc.stop();
            if (ctx.close) ctx.close();
          }, 180);
        } catch (err) {
          console.error(err);
        }
      }

      function queueRowHref(leadId) {
        var url = new URL(CFG.baseUrl, window.location.origin);
        url.searchParams.set("tab", "drive");
        url.searchParams.set("view", "trip-ops");
        url.searchParams.set("lead_id", String(leadId || 0));
        return url.toString();
      }

      function renderQueuePanel(items, selectedLeadId) {
        var root = document.getElementById("sd-op-queue-panel");
        if (!root) return;

        var html = "";
        html += \'<div class="sd-op-card">\';
        html += \'<div class="sd-op-card-head">\';
        html += \'<h2>Queue</h2>\';
        html += \'<div class="sd-op-sub">Action-first queue. Waiting quotes demand immediate attention.</div>\';
        html += \'</div>\';

        if (!items || !items.length) {
          html += "<p>No active queue items.</p>";
          html += "</div>";
          root.innerHTML = html;
          return;
        }

        html += \'<div class="sd-op-queue">\';

        items.forEach(function(item){
          var leadId = Number(item.lead_id || 0);
          var classes = "sd-op-queue-row";
          if (leadId === Number(selectedLeadId || 0)) classes += " is-selected";
          if ((item.bucket || "") === "quotes_waiting") classes += " is-alert";

          html += \'<a class="\' + classes + \'" href="\' + escapeHtml(queueRowHref(leadId)) + \'">\';
          html +=   \'<div class="sd-op-queue-main">\';
          html +=     \'<div class="sd-op-queue-title">\' + escapeHtml(item.customer_name || ("Lead #" + leadId)) + \'</div>\';
          html +=     \'<div class="sd-op-queue-route">\' + escapeHtml((item.pickup_text || "") + " → " + (item.dropoff_text || "")) + \'</div>\';
          html +=   \'</div>\';
          html +=   \'<div class="sd-op-queue-meta">\';
          html +=     \'<div>\' + escapeHtml(item.bucket_label || "Queue") + \'</div>\';
          html +=     \'<div>\' + escapeHtml(item.status_summary || "Open") + \'</div>\';
          html +=     \'<div>\' + escapeHtml(item.next_action_label || "Open") + \'</div>\';
          html +=   \'</div>\';
          html += \'</a>\';
        });

        html += "</div>";
        html += "</div>";

        root.innerHTML = html;
      }

      function updateQueueSummary(count, waitingQuotes) {
        var countEl = document.getElementById("sd-queue-count");
        var badgeEl = document.getElementById("sd-queue-waiting-badge");
        var toggleEl = document.getElementById("sd-queue-toggle");

        if (countEl) countEl.textContent = String(Number(count || 0));

        if (badgeEl) {
          if (Number(waitingQuotes || 0) > 0) {
            badgeEl.style.display = "";
            badgeEl.textContent = String(waitingQuotes) + " quote" + (Number(waitingQuotes) === 1 ? "" : "s");
          } else {
            badgeEl.style.display = "none";
            badgeEl.textContent = "";
          }
        }

        if (toggleEl) {
          if (Number(waitingQuotes || 0) > 0) toggleEl.classList.add("is-alert");
          else toggleEl.classList.remove("is-alert");
        }
      }

      function describeInstallInstructions() {
        return "Use your browser share/menu controls to Add to Home Screen if supported.";
      }

      function announceQueueChange(snapshot) {
        var waitingQuotes = Number(snapshot.waiting_quotes || 0);
        var count = Number(snapshot.count || 0);

        if (!state.firstSnapshotSeen) {
          state.firstSnapshotSeen = true;
          state.lastSignature = String(snapshot.signature || "");
          state.lastCount = count;
          state.lastWaitingQuotes = waitingQuotes;
          updateQueueSummary(count, waitingQuotes);
          renderQueuePanel(snapshot.items || [], snapshot.selected_lead_id || 0);
          return;
        }

        var signatureChanged = String(snapshot.signature || "") !== String(state.lastSignature || "");
        var waitingIncreased = waitingQuotes > Number(state.lastWaitingQuotes || 0);
        var countIncreased = count > Number(state.lastCount || 0);

        updateQueueSummary(count, waitingQuotes);

        if (CFG.activeView === "queue") {
          renderQueuePanel(snapshot.items || [], snapshot.selected_lead_id || 0);
        }

        if (signatureChanged) {
          if (waitingIncreased) {
            setActionStatus("New quote waiting for operator action.");
            setMonitorMessage("Queue changed. Waiting quote detected.");
            pulseAlertUI(true);
            tryVibrate();
            tryBeep();
            window.setTimeout(function(){ pulseAlertUI(false); }, 5000);
          } else if (countIncreased) {
            setActionStatus("Queue updated. New lead entered the operator queue.");
            setMonitorMessage("Queue changed. Review latest items.");
            pulseAlertUI(true);
            tryVibrate();
            tryBeep();
            window.setTimeout(function(){ pulseAlertUI(false); }, 3500);
          } else {
            setMonitorMessage("Queue changed. Live monitor updated.");
          }
        }

        state.lastSignature = String(snapshot.signature || "");
        state.lastCount = count;
        state.lastWaitingQuotes = waitingQuotes;
      }

      async function fetchQueueSnapshot() {
        var body = new URLSearchParams();
        body.append("action", "sd_operator_queue_snapshot");
        body.append("nonce", CFG.nonce);
        body.append("limit", String(CFG.queueLimit));

        var res = await fetch(CFG.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
          },
          body: body.toString()
        });

        var json = await res.json();

        if (!json || json.success !== true) {
          throw new Error((json && json.data && json.data.message) ? json.data.message : "Queue snapshot failed.");
        }

        return json.data || {};
      }

      async function pollQueueOnce() {
        if (document.visibilityState !== "visible") {
          setMonitorState("Background");
          setMonitorMessage("Page hidden. Monitoring slows down until page is visible again.");
          return;
        }

        setMonitorState("Live");
        var snapshot = await fetchQueueSnapshot();
        announceQueueChange(snapshot);

        if (Number(snapshot.waiting_quotes || 0) > 0) {
          setMonitorMessage("Foreground monitoring active. Waiting quotes need attention.");
        } else {
          setMonitorMessage("Foreground monitoring active. No waiting quotes right now.");
        }
      }

      function stopPolling() {
        if (state.pollTimer) {
          window.clearInterval(state.pollTimer);
          state.pollTimer = null;
        }
      }

      function startPolling() {
        stopPolling();

        var interval = (document.visibilityState === "visible") ? CFG.pollMsVisible : CFG.pollMsHidden;

        state.pollTimer = window.setInterval(function(){
          pollQueueOnce().catch(function(err){
            console.error(err);
            setMonitorState("Error");
            setMonitorMessage("Live monitor could not refresh.");
          });
        }, interval);
      }

      function safeCallInstall() {
        refreshDebug();
        maybeUnlockAudio();

        if (!window.SDOperatorPWA || !window.SDOperatorPWA.promptInstall) {
          setActionStatus("PWA JS not loaded.");
          return;
        }

        setActionStatus("Checking install availability...");

        window.SDOperatorPWA.promptInstall()
          .then(function(res){
            refreshDebug();
            if (res && res.ok) {
              setActionStatus("Install result: " + (res.code || "ok"));
            } else {
              setActionStatus(describeInstallInstructions());
            }
          })
          .catch(function(err){
            console.error(err);
            refreshDebug();
            setActionStatus(describeInstallInstructions());
          });
      }

      function safeCallAlerts() {
        refreshDebug();
        maybeUnlockAudio();

        if (!window.SDOperatorPush || !window.SDOperatorPush.ensurePushSubscription) {
          setActionStatus("Push JS not loaded.");
          return;
        }

        setActionStatus("Requesting alert permission/subscription...");

        window.SDOperatorPush.ensurePushSubscription({ userGesture: true })
          .then(function(res){
            refreshDebug();
            if (res && res.ok) {
              setActionStatus("Alerts enabled.");
            } else if (res && res.code === "no-push-manager") {
              setActionStatus("This browser tab has no PushManager. Foreground live monitor remains active.");
            } else {
              setActionStatus("Alerts result: " + ((res && res.code) ? res.code : "no-change"));
            }
          })
          .catch(function(err){
            console.error(err);
            refreshDebug();
            setActionStatus("Enable alerts failed.");
          });
      }

      function safeCallTest() {
        refreshDebug();
        maybeUnlockAudio();

        if (!window.SDOperatorPush || !window.SDOperatorPush.sendTestPush) {
          setActionStatus("Push JS not loaded.");
          return;
        }

        setActionStatus("Sending test alert...");

        window.SDOperatorPush.sendTestPush()
          .then(function(res){
            refreshDebug();
            var sent = res && typeof res.sent !== "undefined" ? Number(res.sent) : 0;
            if (sent > 0) {
              setActionStatus("Test alert sent.");
            } else {
              setActionStatus("No saved alert subscription yet.");
            }
          })
          .catch(function(err){
            console.error(err);
            refreshDebug();
            setActionStatus("Test alert failed.");
          });
      }

      document.addEventListener("visibilitychange", function(){
        startPolling();
        pollQueueOnce().catch(function(){});
      });

      document.addEventListener("DOMContentLoaded", function(){
        var installBtn = document.getElementById("sd-install-pwa-btn");
        var alertsBtn  = document.getElementById("sd-enable-alerts-btn");
        var testBtn    = document.getElementById("sd-test-alert-btn");

        if (installBtn) installBtn.addEventListener("click", safeCallInstall);
        if (alertsBtn) alertsBtn.addEventListener("click", safeCallAlerts);
        if (testBtn) testBtn.addEventListener("click", safeCallTest);

        document.addEventListener("click", maybeUnlockAudio, { passive: true });
        document.addEventListener("touchstart", maybeUnlockAudio, { passive: true });

        window.addEventListener("sd:pwa-state", refreshDebug);
        window.addEventListener("sd:push-state", refreshDebug);

        window.addEventListener("sd:pwa-action-result", function(e){
          var d = e && e.detail ? e.detail : {};
          if (d.message) {
            setActionStatus(d.message);
          }
          refreshDebug();
        });

        refreshDebug();
        setMonitorState("Live");
        setMonitorMessage("Foreground queue monitoring enabled for this page.");

        pollQueueOnce().catch(function(err){
          console.error(err);
          setMonitorState("Error");
          setMonitorMessage("Live monitor could not refresh.");
        });

        startPolling();

        window.setTimeout(refreshDebug, 500);
        window.setTimeout(refreshDebug, 1500);
        window.setInterval(refreshDebug, 3000);
      });
    })();
    </script>';
  }

  private static function render_queue_panel(array $queue_items, int $selected_lead_id, string $base_url) : string {
    $html  = '<div class="sd-op-card">';
    $html .= '  <div class="sd-op-card-head">';
    $html .= '    <h2>Queue</h2>';
    $html .= '    <div class="sd-op-sub">Action-first queue. Waiting quotes demand immediate attention.</div>';
    $html .= '  </div>';

    if (empty($queue_items)) {
      $html .= '<p>No active queue items.</p>';
      $html .= '</div>';
      return $html;
    }

    $html .= '<div class="sd-op-queue">';
    foreach ($queue_items as $item) {
      $lead_id = (int) ($item['lead_id'] ?? 0);

      $href = add_query_arg([
        'tab'     => 'drive',
        'view'    => 'trip-ops',
        'lead_id' => $lead_id,
      ], $base_url);

      $classes = 'sd-op-queue-row';
      if ($lead_id === $selected_lead_id) $classes .= ' is-selected';
      if ((string) ($item['bucket'] ?? '') === 'quotes_waiting') $classes .= ' is-alert';

      $bucket_label = class_exists('SD_Module_OperatorQueue', false)
        ? SD_Module_OperatorQueue::display_bucket_label((string) ($item['bucket'] ?? ''))
        : 'Queue';

      $status_summary = class_exists('SD_Module_OperatorQueue', false)
        ? SD_Module_OperatorQueue::display_status_summary($item)
        : 'Open';

      $html .= '<a class="' . esc_attr($classes) . '" href="' . esc_url($href) . '">';
      $html .= '  <div class="sd-op-queue-main">';
      $html .= '    <div class="sd-op-queue-title">' . esc_html(($item['customer_name'] ?? '') !== '' ? (string) $item['customer_name'] : ('Lead #' . $lead_id)) . '</div>';
      $html .= '    <div class="sd-op-queue-route">' . esc_html(trim((string) ($item['pickup_text'] ?? '') . ' → ' . (string) ($item['dropoff_text'] ?? ''))) . '</div>';
      $html .= '  </div>';
      $html .= '  <div class="sd-op-queue-meta">';
      $html .= '    <div>' . esc_html($bucket_label) . '</div>';
      $html .= '    <div>' . esc_html($status_summary) . '</div>';
      $html .= '    <div>' . esc_html((string) ($item['next_action_label'] ?? 'Open')) . '</div>';
      $html .= '  </div>';
      $html .= '</a>';
    }
    $html .= '</div>';
    $html .= '</div>';

    return $html;
  }

  private static function operator_app_url() : string {
    $post = get_post();
    if ($post instanceof WP_Post) {
      $url = get_permalink($post);
      if (is_string($url) && $url !== '') {
        return $url;
      }
    }

    $page = get_page_by_path('operator', OBJECT, 'page');
    if ($page instanceof WP_Post) {
      $url = get_permalink($page);
      if (is_string($url) && $url !== '') {
        return $url;
      }
    }

    return home_url('/operator/');
  }

  private static function resolve_selected_lead_id_fallback() : int {
    $lead_id = isset($_GET['lead_id']) ? absint(wp_unslash($_GET['lead_id'])) : 0;
    if ($lead_id > 0) {
      return $lead_id;
    }

    $legacy_ride_id = isset($_GET['ride_id']) ? absint(wp_unslash($_GET['ride_id'])) : 0;
    if ($legacy_ride_id > 0 && class_exists('SD_Module_OperatorQueue', false) && method_exists('SD_Module_OperatorQueue', 'lead_id_for_ride')) {
      return (int) SD_Module_OperatorQueue::lead_id_for_ride($legacy_ride_id);
    }

    return 0;
  }

  private static function current_user_tenant_id() : int {
    if (class_exists('SD_TenantAccess', false) && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      return (int) SD_TenantAccess::current_user_tenant_id();
    }

    if (!is_user_logged_in()) return 0;

    return (int) get_user_meta(get_current_user_id(), 'sd_tenant_id', true);
  }
}