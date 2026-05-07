<?php
// debug_session.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h2>Session Debug Information</h2>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "Session Name: " . session_name() . "\n";
echo "Cookie Parameters:\n";
print_r(session_get_cookie_params());
echo "\nSession Contents:\n";
print_r($_SESSION);
echo "</pre>";

// Test session persistence
if (!isset($_SESSION['debug_test'])) {
    $_SESSION['debug_test'] = date('Y-m-d H:i:s');
    echo "<p>✅ Session test variable set: " . $_SESSION['debug_test'] . "</p>";
} else {
    echo "<p>✅ Session test variable exists: " . $_SESSION['debug_test'] . "</p>";
}
?>