<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// fallback name if no user info is available
$displayName = $_SESSION['username']
    ?? $_SESSION['name']
    ?? $_SESSION['user_id']
    ?? 'Guest';
?>
<footer class="footer vb-footer">
    <div class="footer-container container">
        <p>&copy; <?= date('Y') ?> PSPF Transport Booking &middot; Version 1.0.0</p>

        <?php if (isset($_SESSION['user_id'])): ?>
            <p>
                Logged in as <?= htmlspecialchars($displayName) ?>
                &middot; <a href="logout.php">Logout</a>
            </p>
        <?php endif; ?>
    </div>
</footer>
