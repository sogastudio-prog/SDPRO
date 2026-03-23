self.addEventListener("install", function (event) {
  self.skipWaiting();
});

self.addEventListener("activate", function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener("push", function (event) {
  let data = {
    title: "SoloDrive Alert",
    body: "Operator attention required.",
    url: "/operator/trips/",
    tag: "sd-default-alert",
    requireInteraction: false
  };

  try {
    const incoming = event.data ? event.data.json() : {};
    data = Object.assign(data, incoming || {});
  } catch (e) {}

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      tag: data.tag,
      requireInteraction: !!data.requireInteraction,
      data: {
        url: data.url || "/operator/trips/"
      }
    })
  );
});

self.addEventListener("notificationclick", function (event) {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || "/operator/trips/";

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientsArr) => {
      for (const client of clientsArr) {
        if (client.url.indexOf("/operator/") !== -1 && "focus" in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});