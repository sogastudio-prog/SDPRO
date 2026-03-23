(function () {
  "use strict";

  if (!window.SD_OPERATOR_PWA) return;

  const CFG = window.SD_OPERATOR_PWA;

  function setPushState(value) {
    document.documentElement.setAttribute("data-sd-push", value);
    window.dispatchEvent(
      new CustomEvent("sd:push-state", {
        detail: { value: value }
      })
    );
  }

  function emitAction(type, payload) {
    window.dispatchEvent(
      new CustomEvent("sd:pwa-action-result", {
        detail: Object.assign({ type: type }, payload || {})
      })
    );
  }

  function getNotificationPermissionSafe() {
    if (!("Notification" in window)) return "unsupported";
    return Notification.permission || "default";
  }

  function hasRequiredPushApis() {
    if (!("serviceWorker" in navigator)) return { ok: false, state: "no-service-worker" };
    if (!("PushManager" in window)) return { ok: false, state: "no-push-manager" };
    if (!("Notification" in window)) return { ok: false, state: "no-notification-api" };
    return { ok: true, state: "ok" };
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
    const rawData = window.atob(base64);
    return Uint8Array.from(Array.prototype.map.call(rawData, function (c) {
      return c.charCodeAt(0);
    }));
  }

  async function api(path, method, body) {
    const res = await fetch(CFG.restBase + path, {
      method: method,
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": CFG.restNonce
      },
      body: body ? JSON.stringify(body) : undefined
    });

    const json = await res.json();
    if (!res.ok) {
      throw new Error((json && json.message) || "Request failed");
    }
    return json;
  }

  async function syncPushState() {
    const support = hasRequiredPushApis();

    if (!support.ok) {
      setPushState(support.state);
      return null;
    }

    if (!CFG.vapidPublicKey) {
      setPushState("missing-vapid");
      return null;
    }

    const permission = getNotificationPermissionSafe();

    if (permission === "denied") {
      setPushState("denied");
      return null;
    }

    try {
      const reg = window.SD_OPERATOR_SW_REG || await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();

      if (sub) {
        setPushState("subscribed");
      } else if (permission === "granted") {
        setPushState("granted-no-subscription");
      } else {
        setPushState("idle");
      }

      return sub;
    } catch (err) {
      console.error("[SD Operator Push] sync failed:", err);
      setPushState("sw-not-ready");
      return null;
    }
  }

  async function ensurePushSubscription(options) {
    const opts = options || {};
    const userGesture = opts.userGesture === true;

    const support = hasRequiredPushApis();
    if (!support.ok) {
      setPushState(support.state);
      emitAction("alerts", {
        ok: false,
        code: support.state,
        message: "Push is not supported in this browser/context."
      });
      return { ok: false, code: support.state };
    }

    if (!CFG.vapidPublicKey) {
      setPushState("missing-vapid");
      emitAction("alerts", {
        ok: false,
        code: "missing-vapid",
        message: "VAPID key missing."
      });
      return { ok: false, code: "missing-vapid" };
    }

    let permission = getNotificationPermissionSafe();

    if (permission === "default" && !userGesture) {
      setPushState("requires-user-gesture");
      emitAction("alerts", {
        ok: false,
        code: "requires-user-gesture",
        message: "Tap Enable alerts directly."
      });
      return { ok: false, code: "requires-user-gesture" };
    }

    if (permission === "default") {
      try {
        permission = await Notification.requestPermission();
      } catch (err) {
        console.error("[SD Operator Push] permission request failed:", err);
        setPushState("permission-request-failed");
        emitAction("alerts", {
          ok: false,
          code: "permission-request-failed",
          message: "Permission request failed."
        });
        return { ok: false, code: "permission-request-failed" };
      }
    }

    if (permission === "denied") {
      setPushState("denied");
      emitAction("alerts", {
        ok: false,
        code: "denied",
        message: "Notifications are blocked."
      });
      return { ok: false, code: "denied" };
    }

    if (permission !== "granted") {
      setPushState("permission-not-granted");
      emitAction("alerts", {
        ok: false,
        code: "permission-not-granted",
        message: "Notification permission not granted."
      });
      return { ok: false, code: "permission-not-granted" };
    }

    let reg = null;

    try {
      reg = window.SD_OPERATOR_SW_REG || await navigator.serviceWorker.ready;
    } catch (err) {
      console.error("[SD Operator Push] service worker not ready:", err);
      setPushState("sw-not-ready");
      emitAction("alerts", {
        ok: false,
        code: "sw-not-ready",
        message: "Service worker not ready."
      });
      return { ok: false, code: "sw-not-ready" };
    }

    let sub = null;

    try {
      sub = await reg.pushManager.getSubscription();

      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(CFG.vapidPublicKey)
        });
      }
    } catch (err) {
      console.error("[SD Operator Push] browser subscribe failed:", err);
      setPushState("subscribe-failed");
      emitAction("alerts", {
        ok: false,
        code: "subscribe-failed",
        message: "Browser subscription failed."
      });
      return { ok: false, code: "subscribe-failed" };
    }

    const json = sub.toJSON();

    let deviceId = localStorage.getItem("sd_operator_device_id");
    if (!deviceId) {
      if (window.crypto && typeof window.crypto.randomUUID === "function") {
        deviceId = window.crypto.randomUUID();
      } else {
        deviceId = "sd-" + String(Date.now()) + "-" + String(Math.random()).slice(2);
      }
      localStorage.setItem("sd_operator_device_id", deviceId);
    }

    json.device_id = deviceId;

    try {
      const res = await api("push-subscribe", "POST", json);
      setPushState("subscribed");
      emitAction("alerts", {
        ok: true,
        code: "subscribed",
        message: "Alerts enabled.",
        response: res
      });
      return { ok: true, code: "subscribed", response: res };
    } catch (err) {
      console.error("[SD Operator Push] server subscribe failed:", err);
      setPushState("server-save-failed");
      emitAction("alerts", {
        ok: false,
        code: "server-save-failed",
        message: "Subscription could not be saved on server."
      });
      return { ok: false, code: "server-save-failed" };
    }
  }

  async function sendTestPush() {
    try {
      const res = await api("push-test", "POST", {});
      emitAction("test", {
        ok: true,
        code: (res && Number(res.sent || 0) > 0) ? "sent" : "no-subscribers",
        message: (res && Number(res.sent || 0) > 0)
          ? "Test alert sent."
          : "No saved alert subscription yet.",
        response: res
      });
      return res;
    } catch (err) {
      emitAction("test", {
        ok: false,
        code: "test-failed",
        message: "Test alert failed."
      });
      throw err;
    }
  }

  window.SDOperatorPush = {
    ensurePushSubscription,
    sendTestPush,
    syncPushState
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      syncPushState().catch(function () {
        setPushState("error");
      });
    });
  } else {
    syncPushState().catch(function () {
      setPushState("error");
    });
  }
})();