<!-- Modern Footer - Matching Navigation Bar Style -->
<footer class="footer" style="background: linear-gradient(135deg, var(--pspf-primary) 0%, var(--pspf-primary-dark) 100%); color: white; padding: 1.25rem 0; margin-top: 2rem; box-shadow: 0 -4px 12px rgba(0,0,0,0.1);">
    <div class="container-fluid">
        <!-- Main Footer Content -->
        <div class="footer-content">
            <!-- Left Section - Copyright & Info -->
            <div class="footer-section">
                <div class="d-flex align-items-center gap-3">
                    <img src="/pspf_crm/api/uploads/pspflogo2.png" alt="PSPF Logo" style="height: 32px; width: auto; opacity: 0.9;">
                    <div class="footer-divider" style="width: 1px; height: 30px; background: rgba(255,255,255,0.2);"></div>
                    <div>
                        <span class="fw-semibold" style="font-size: 1rem;">PSPF CRM</span>
                        <p class="mb-0" style="font-size: 0.8rem; opacity: 0.8;">&copy; <?= date('Y') ?> All rights reserved</p>
                    </div>
                </div>
            </div>

            <!-- Right Section - User Info & Actions -->
            <div class="footer-section">
                <div class="d-flex align-items-center gap-3">
                    <!-- User Badge -->
                    <div class="user-footer-badge d-flex align-items-center gap-2" style="background: rgba(255,255,255,0.1); padding: 0.4rem 1rem; border-radius: 40px; border: 1px solid rgba(255,255,255,0.1);">
                        <span class="role-indicator" style="width: 8px; height: 8px; border-radius: 50%; background: <?php 
                            if ($isSuperAdmin) echo '#ffffff';
                            elseif ($isAdmin) echo '#F6AE2D';
                            elseif ($isAgent) echo '#7FC8F8';
                            else echo '#C62E65';
                        ?>;"></span>
                        <span style="font-size: 0.9rem; font-weight: 500;"><?= htmlspecialchars($UserUsername) ?></span>
                        <span class="badge" style="background: <?php 
                            if ($isSuperAdmin) echo 'black';
                            elseif ($isAdmin) echo '#F6AE2D';
                            elseif ($isAgent) echo '#7FC8F8';
                            else echo '#C62E65';
                        ?>; color: <?= ($isAdmin || $isAgent) ? '#1e293b' : 'white' ?>; font-size: 0.65rem; padding: 0.25rem 0.6rem; border-radius: 20px;">
                            <?= roleLabel(getActiveRoleString()) ?>
                        </span>
                    </div>

                    <!-- Logout Button -->
                    <a href="/pspf_crm/api/signin/logout.php" class="btn btn-outline-light btn-sm" style="border-radius: 40px; padding: 0.4rem 1.2rem; border-width: 1px; font-weight: 500;">
                        <i class="bi bi-box-arrow-right me-1"></i>
                        <span class="d-none d-sm-inline">Logout</span>
                    </a>
                </div>
            </div>
        </div>


        <!-- Version and Status Bar -->
        <div class="footer-status-bar d-flex justify-content-between align-items-center mt-2 pt-2" style="border-top: 1px solid rgba(255,255,255,0.05); font-size: 0.7rem; opacity: 0.7;">
            <div class="d-flex align-items-center gap-3">
                <span><i class="bi bi-code-slash me-1"></i> v1.0.0</span>
                <span><i class="bi bi-dot"></i></span>
                <span><i class="bi bi-shield-check me-1"></i> Secure</span>
            </div>
            <div>
                <span id="live-time"></span>
            </div>
        </div>
    </div>
</footer>

<style>
/* Footer Styles - Matching Navigation Bar */
.footer {
    font-family: 'Titillium Web', sans-serif;
    transition: all 0.3s ease;
    position: relative;
    z-index: 100;
}

.footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.footer-section {
    flex: 1 1 auto;
}

.footer-links {
    display: flex;
    gap: 1.5rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.footer-link {
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.2s;
    display: flex;
    align-items: center;
}

.footer-link:hover {
    color: white;
    transform: translateY(-2px);
}

.footer-link i {
    font-size: 0.9rem;
    transition: transform 0.2s;
}

.footer-link:hover i {
    transform: scale(1.1);
}

/* Mobile Footer Links */
.mobile-footer-link {
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    font-size: 0.7rem;
    transition: all 0.2s;
    padding: 0.5rem;
    border-radius: 8px;
    flex: 1;
}

.mobile-footer-link:hover,
.mobile-footer-link:active {
    color: white;
    background: rgba(255, 255, 255, 0.1);
}

.mobile-footer-link i {
    font-size: 1.2rem;
    margin-bottom: 0.2rem;
}

/* User Badge in Footer */
.user-footer-badge {
    backdrop-filter: blur(5px);
    transition: all 0.2s;
}

.user-footer-badge:hover {
    background: rgba(255, 255, 255, 0.15) !important;
    transform: translateY(-2px);
}

.role-indicator {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

/* Footer Status Bar */
.footer-status-bar {
    font-family: monospace;
}

#live-time {
    background: rgba(0, 0, 0, 0.2);
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
}

/* Responsive Breakpoints */
@media (max-width: 991.98px) {
    .footer-content {
        flex-direction: column;
        text-align: center;
        gap: 1.5rem;
    }
    
    .footer-section {
        width: 100%;
    }
    
    .footer-section:first-child {
        display: flex;
        justify-content: center;
    }
    
    .footer-links {
        justify-content: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .footer-link {
        padding: 0.3rem 0.8rem;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 20px;
    }
}

@media (max-width: 768px) {
    .footer {
        padding: 1rem 0 !important;
    }
    
    .footer-status-bar {
        flex-direction: column;
        gap: 0.5rem;
        text-align: center;
    }
    
    .user-footer-badge {
        padding: 0.3rem 0.8rem !important;
    }
    
    .user-footer-badge span:first-of-type {
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
}

@media (max-width: 480px) {
    .footer {
        margin-top: 1.5rem !important;
    }
    
    .btn-outline-light {
        padding: 0.3rem 0.8rem !important;
    }
    
    .btn-outline-light span {
        display: none !important;
    }
    
    .btn-outline-light i {
        margin-right: 0 !important;
    }
    
    .user-footer-badge {
        padding: 0.25rem 0.6rem !important;
    }
    
    .user-footer-badge .badge {
        display: none;
    }
}

/* Landscape Mode */
@media (max-height: 480px) and (orientation: landscape) {
    .footer {
        padding: 0.5rem 0 !important;
    }
    
    .footer-content {
        gap: 0.75rem;
    }
    
    .footer-status-bar {
        font-size: 0.65rem !important;
    }
}

/* Print Styles */
@media print {
    .footer {
        display: none;
    }
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
    .footer {
        background: linear-gradient(135deg, #3D5C80 0%, #406997 100%) !important;
    }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
    .footer-link,
    .mobile-footer-link,
    .btn-outline-light {
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .user-footer-badge {
        min-height: 44px;
    }
}

/* Animation for hover effects */
.footer-link,
.mobile-footer-link,
.btn-outline-light,
.user-footer-badge {
    transition: all 0.2s ease;
}

/* Focus states for accessibility */
.footer-link:focus-visible,
.mobile-footer-link:focus-visible,
.btn-outline-light:focus-visible {
    outline: 2px solid white;
    outline-offset: 2px;
    border-radius: 4px;
}
</style>

<script>
// Live time update for footer
function updateLiveTime() {
    const timeElement = document.getElementById('live-time');
    if (timeElement) {
        const now = new Date();
        const options = { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: true 
        };
        timeElement.innerHTML = `<i class="bi bi-clock me-1"></i>${now.toLocaleTimeString('en-US', options)}`;
    }
}

// Update time every second
setInterval(updateLiveTime, 1000);

// Initial call
document.addEventListener('DOMContentLoaded', function() {
    updateLiveTime();
    
    // Add smooth scroll to top functionality
    const footerLinks = document.querySelectorAll('.footer-link, .mobile-footer-link');
    footerLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Don't prevent default - let navigation happen
            // But we can add a small visual feedback
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = '';
            }, 200);
        });
    });
    
    // Check for active page and highlight footer links
    const currentPath = window.location.pathname;
    const pageName = currentPath.split('/').pop();
    
    document.querySelectorAll('.footer-link, .mobile-footer-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.includes(pageName) && pageName !== '') {
            link.style.color = 'white';
            link.style.fontWeight = '600';
            if (link.classList.contains('footer-link')) {
                link.style.background = 'rgba(255,255,255,0.15)';
                link.style.borderRadius = '20px';
            }
        }
    });
});

// Handle orientation change
window.addEventListener('orientationchange', function() {
    setTimeout(updateLiveTime, 100);
});

// Handle visibility change (tab inactive/active)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        updateLiveTime();
    }
});
</script>