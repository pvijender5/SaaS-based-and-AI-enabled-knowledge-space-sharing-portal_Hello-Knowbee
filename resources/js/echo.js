import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true
});



// // Function to set up the listener
// export function setupNotificationListener(userId) {
//     window.Echo.private(`notifications.${userId}`)
//         .listen('NotificationSent', (event) => {
//             console.log('New notification:', event.notification);
//             // Update the UI with the new notification
//         });
// }
