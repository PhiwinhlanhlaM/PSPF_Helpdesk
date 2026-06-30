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
            <p>&copy; <?= date('Y') ?> Transport booking. All rights reserved.</p>
            <p>
                Version 1.0.0 | 
                <a href="/contact.php">Contact Support</a> | 
                <a href="/knowledge/list.php">Knowledge Base</a>
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

        <div class="social-links">
            <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
            <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
        </div>

    </div>
</footer>
