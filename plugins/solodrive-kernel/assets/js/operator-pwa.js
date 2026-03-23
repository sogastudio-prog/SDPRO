(function () {
  "use strict";

  if (!window.SD_OPERATOR_PWA) return;

  const CFG = window.SD_OPERATOR_PWA;
  let deferredInstallPrompt = null;

  function setState(name, value) {
    document.documentElement.setAttribute(name, value);
    window.dispatchEvent(
      new CustomEvent("sd:pwa-state", {
        detail: { name: name, value: value }
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

  function isStandalone() {
    try {
      if (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) {
        return true;
      }
    } catch (err) {}

    return window.navigator.standalone === true;
  }

  async function registerServiceWorker() {
    if (!("serviceWorker" in navigator)) {
      setState("data-sd-sw", "unsupported");
      return null;
    }

    try {
      const reg = await navigator.serviceWorker.register(CFG.swUrl, {
        scope: CFG.scopePath || "/operator/"
      });

      window.SD_OPERATOR_SW_REG = reg;

      try {
        await navigator.serviceWorker.ready;
      } catch (err) {}

      setState("data-sd-sw", "ready");
      return reg;
    } catch (err) {
      console.error("[SD Operator PWA] SW registration failed:", err);
      setState("data-sd-sw", "failed");
      emitAction("install", {
        ok: false,
        code: "sw-register-failed",
        message: "Service worker registration failed."
      });
      return null;
    }
  }

  function bindInstallPrompt() {
    window.addEventListener("beforeinstallprompt", function (e) {
      e.preventDefault();
      deferredInstallPrompt = e;
      setState("data-sd-install", "available");
      window.dispatchEvent(new CustomEvent("sd:pwa-install-available"));
    });

    window.addEventListener("appinstalled", function () {
      deferredInstallPrompt = null;
      setState("data-sd-install", "installed");
      emitAction("install", {
        ok: true,
        code: "installed",
        message: "App installed."
      });
    });
  }

  async function promptInstall() {
    if (isStandalone()) {
      setState("data-sd-install", "installed");
      emitAction("install", {
        ok: true,
        code: "already-installed",
        message: "App is already installed."
      });
      return { ok: true, code: "already-installed" };
    }

    if (!deferredInstallPrompt) {
      setState("data-sd-install", "browser");
      emitAction("install", {
        ok: false,
        code: "install-unavailable",
        message: "Install prompt not available in this browser/context."
      });
      return { ok: false, code: "install-unavailable" };
    }

    deferredInstallPrompt.prompt();
    const result = await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;

    const outcome = (result && result.outcome) ? result.outcome : "used";
    setState("data-sd-install", outcome);

    if (outcome === "accepted") {
      emitAction("install", {
        ok: true,
        code: "accepted",
        message: "Install accepted."
      });
      return { ok: true, code: "accepted" };
    }

    emitAction("install", {
      ok: false,
      code: outcome,
      message: "Install prompt dismissed or unavailable."
    });
    return { ok: false, code: outcome };
  }

  function bootInstallState() {
    if (isStandalone()) {
      setState("data-sd-install", "installed");
      return;
    }

    setState("data-sd-install", "browser");
  }

  window.SDOperatorPWA = {
    registerServiceWorker,
    promptInstall,
    isStandalone
  };

  bindInstallPrompt();
  bootInstallState();
  registerServiceWorker();
})();