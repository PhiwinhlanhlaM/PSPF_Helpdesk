<?php
if (!isset($_SESSION)) { session_start(); }

// fallback name if no user info is available
$displayName = $_SESSION['username'] 
    ?? $_SESSION['name'] 
    ?? $_SESSION['user_id'] 
    ?? 'Guest';
?>
<footer class="footer">
    <div class="footer-container">

        <div class="logout-link">
            <p>&copy; <?= date('Y') ?> PSPF Transport booking Form. All rights reserved.</p>
            <p>
                Version 1.0.0
            </p>

            <?php if (isset($_SESSION['user_id'])): ?>
                <p>
                    <small>
                        Logged in as <?= htmlspecialchars($displayName) ?> 
                        | <a href="logout.php">Logout</a>
                    </small>
                </p>
            <?php endif; ?>
        </div>

        

    </div>
</footer>
