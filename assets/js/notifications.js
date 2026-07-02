// Shared notification functions for all pages
// Expects notificationBell, notificationBadge, notificationDropdown elements in the DOM

function fetchNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    if (!dropdown) return;

    // Determine the notification endpoint path based on current page location
    const isAdmin = window.location.pathname.includes('/admin/');
    const notifPath = isAdmin ? 'notification.php' : 'notification.php';
    const apiPath = isAdmin ? '../notif/api.php' : '../notif/api.php';

    try {
        const resp = fetch(notifPath, { method: 'GET', cache: 'no-cache' });
        resp.then(res => res.text())
            .then(html => {
                if (html && html.trim().length > 0) {
                    dropdown.innerHTML = html;
                } else {
                    dropdown.innerHTML = '<li class="dropdown-item text-center text-muted small">No notifications</li>';
                }

                dropdown.querySelectorAll('.notification-item').forEach(item => {
                    item.addEventListener('click', function(e) {
                        const notificationId = this.getAttribute('data-notification-id');
                        if (notificationId) {
                            fetch(apiPath, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=mark_read&notification_id=' + notificationId
                            }).catch(err => console.error('Failed to mark notification as read:', err));
                            this.classList.remove('unread');
                        }
                    });
                });

                const unread = dropdown.querySelectorAll('.notification-item.unread, li[data-unread="1"]').length;
                if (unread > 0) {
                    badge.textContent = String(unread);
                    badge.classList.remove('visually-hidden');
                } else {
                    badge.classList.add('visually-hidden');
                }
            });
    } catch (err) {
        dropdown.innerHTML = '<li class="dropdown-item text-danger small">Error loading notifications</li>';
        if (badge) badge.classList.add('visually-hidden');
        console.error('Failed to load notifications:', err);
    }
}

// Auto-fetch on page load
document.addEventListener('DOMContentLoaded', function() {
    fetchNotifications();
    // Refresh every 30 seconds
    setInterval(fetchNotifications, 30000);
});
