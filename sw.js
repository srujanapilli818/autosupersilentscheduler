// sw.js — handles background notifications
self.addEventListener('install', event => {
  console.log('Service Worker installed');
  self.skipWaiting(); // Added to ensure quick activation
});

self.addEventListener('activate', event => {
  console.log('Service Worker activated');
  event.waitUntil(self.clients.claim()); // Added to immediately control the page
});

// This is the CRITICAL missing listener for when the user interacts with the notification
// Notifications triggered by the client (via reg.showNotification) will be handled here upon click.
self.addEventListener('notificationclick', event => {
  console.log('Notification clicked!');
  event.notification.close(); // Close the notification

  // Example action: Focus the current page or open a new one
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(clientList => {
      for (const client of clientList) {
        // If the current silent_mode.php is open, focus it
        if (client.url.includes('silent_mode.php') && 'focus' in client) {
          return client.focus();
        }
      }
      // If the page is not open, open it
      if (clients.openWindow) {
        return clients.openWindow('./silent_mode.php');
      }
    })
  );
});

// Optional: Handle when the user dismisses the notification
self.addEventListener('notificationclose', event => {
  console.log('Notification closed!');
});

// This will handle push notifications if you later add server push (Existing Logic)
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : {};
  const title = data.title || "Notification";
  const options = {
    body: data.body || "You have a new message.",
    icon: 'sm.png'
  };
  event.waitUntil(self.registration.showNotification(title, options));
});