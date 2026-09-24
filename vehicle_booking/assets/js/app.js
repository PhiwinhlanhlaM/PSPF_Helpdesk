/* Shared behaviour for Vehicle Booking pages. Loaded from partials/head.php. */

/*
 * Default Back button handler. Pages may still define their own goBack();
 * a later function declaration replaces this one. Previously several pages
 * called goBack() without defining it (or only defined it conditionally),
 * so the Back button did nothing.
 */
function goBack() {
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1 && window.history.length > 1) {
        window.history.back();
        return;
    }
    var home = document.querySelector('.main-navbar .navbar-brand');
    window.location.href = home ? home.getAttribute('href') : 'login.php';
}
