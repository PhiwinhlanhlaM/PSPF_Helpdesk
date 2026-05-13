<!-- Mobile-Responsive Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); padding: 0.5rem 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="<?= getRoleHomePage() ?>">
            <img src="/pspf_crm/api/uploads/pspflogo2.png" alt="PSPF Logo" style="height: 36px; width: auto; margin-right: 8px;">
            <span class="brand-text" style="font-weight: 600; font-size: 1.2rem;">CRM</span>
        </a>

        <!-- Mobile Toggle Buttons -->
        <div class="d-flex align-items-center gap-2">
            <!-- User Menu Toggle (Mobile) - Enhanced with Department -->
            <div class="dropdown d-lg-none">
                <button class="btn btn-link text-white p-1" type="button" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="role-avatar" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: <?php 
                        if ($isSuperAdmin) echo 'black';
                        elseif ($isAdmin) echo '#F6AE2D';
                        elseif ($isAgent) echo '#7FC8F8';
                        else echo '#C62E65';
                    ?>; color: <?= ($isAdmin || $isAgent) ? '#1e293b' : 'white' ?>;">
                        <i class="bi <?= $iconClass ?>" style="font-size: 1rem;"></i>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenuDropdown">
                    <li class="dropdown-header text-muted"><?= htmlspecialchars($UserUsername) ?></li>
                    <!-- Department info in mobile dropdown -->
                    <?php if (!empty($UserDept)): ?>
                        <li class="dropdown-header text-muted small">
                            <i class="bi bi-building me-1"></i><?= htmlspecialchars($UserDept) ?>
                        </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/pspf_crm/api/settings/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
                    <?php
                    $_mobileRoles = getUserRoles();
                    if (count($_mobileRoles) > 1):
                        $_mobileActive = getActiveRole();
                        $_mobileCSRF   = $_SESSION['csrf_token'] ?? '';
                    ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Switch Role</h6></li>
                    <?php foreach ($_mobileRoles as $_mobileRole):
                        $_mobileIcons = ['superadmin'=>'bi-person-gear','admin'=>'bi-shield-fill-check','agent'=>'bi-headset','user'=>'bi-person-fill'];
                        $_mobileIcon  = $_mobileIcons[$_mobileRole] ?? 'bi-person-fill';
                    ?>
                    <li>
                        <form method="POST" action="/pspf_crm/api/switch_role.php">
                            <input type="hidden" name="role" value="<?= htmlspecialchars($_mobileRole) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_mobileCSRF) ?>">
                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2<?= $_mobileRole === $_mobileActive ? ' active fw-semibold' : '' ?>">
                                <i class="bi <?= $_mobileIcon ?>"></i>
                                <?= htmlspecialchars(ucfirst($_mobileRole)) ?>
                                <?php if ($_mobileRole === $_mobileActive): ?><i class="bi bi-check2 ms-auto"></i><?php endif ?>
                            </button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/pspf_crm/api/signin/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>

            <!-- Main Navbar Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" 
                    aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <!-- Collapsible Navigation Menu -->
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Home -->
                <li class="nav-item">
                    <a class="nav-link" href="<?= getRoleHomePage() ?>">
                        <i class="bi bi-house-door me-1"></i> Home
                    </a>
                </li>

                <!-- Self Service -->
                <li class="nav-item">
                    <a class="nav-link" href="/pspf_crm/api/Knowledge_base.php">
                        <i class="bi bi-journal-bookmark-fill me-1"></i> Self Service
                    </a>
                </li>

                <!-- Tickets Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-ticket-perforated me-1"></i> Tickets
                    </a>
                    <ul class="dropdown-menu">
                        <?php if ($isUser): ?>
                            <li><a class="dropdown-item" href="/pspf_crm/api/ticket/query.php"><i class="bi bi-plus-circle me-2"></i>New Ticket</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/pspf_crm/api/ticket/view_all.php"><i class="bi bi-list-ul me-2"></i>My Tickets</a></li>
                        
                        <?php elseif ($isAgent): ?>
                            <li><a class="dropdown-item" href="/pspf_crm/api/agent/agent_view.php"><i class="bi bi-person-workspace me-2"></i>My Tickets</a></li>
                        
                        <?php elseif ($isAdmin): ?>
                            <li><a class="dropdown-item" href="/pspf_crm/api/admin/admin_view.php"><i class="bi bi-building me-2"></i>Department Tickets</a></li>
                        
                        <?php else: ?>
                            <li><a class="dropdown-item" href="/pspf_crm/api/admin/admin_view.php"><i class="bi bi-globe me-2"></i>All Tickets</a></li>
                            <li><a class="dropdown-item" href="/pspf_crm/api/admin/ticket_progress.php"><i class="bi bi-hourglass-split me-2"></i>In Progress</a></li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Orders Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bag me-1"></i> Orders
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/pspf_crm/api/order/food_order.php"><i class="bi bi-cart-plus me-2"></i>New Order</a></li>
                        <?php if (hasRoleInDepartment('admin', 'ICT')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/pspf_crm/api/order/all_orders.php"><i class="bi bi-gear me-2"></i>Manage Orders</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                
                <!-- Reports (SuperAdmin only) -->
                <?php if($isSuperAdmin): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bar-chart me-1"></i> Reports
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/pspf_crm/api/ticket/report_queries.php"><i class="bi bi-journal-text me-2"></i>Query Logs</a></li>
                        <li><a class="dropdown-item" href="/pspf_crm/api/admin/ticket_status_logs.php"><i class="bi bi-clock-history me-2"></i>Activity Logs</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/pspf_crm/api/ticket/ticket_status_summary.php"><i class="bi bi-file-pdf me-2"></i>Export PDF</a></li>
                    </ul>
                </li>
                <?php endif ?>

                <!-- Settings Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear me-1"></i> Settings
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/pspf_crm/api/settings/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
                        <?php if($isSuperAdmin): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/pspf_crm/api/settings/user_management.php"><i class="bi bi-people me-2"></i>User Management</a></li>
                        <?php endif ?>
                    </ul>
                </li>
            </ul>

            <!-- Desktop User Info (hidden on mobile) - Enhanced with Department -->
            <div class="d-none d-lg-flex align-items-center gap-2">
                <!-- User Info Badge with Role and Department -->
                <div class="d-flex align-items-center" style="background: rgba(255,255,255,0.15); border-radius: 30px; padding: 0.25rem 1rem;">
                    <span class="text-white me-2" style="font-size: 0.9rem; font-weight: 500;"><?= htmlspecialchars($UserUsername) ?></span>
                    
                    <!-- Role Badge with Icon -->
                    <?php if ($isUser): ?>
                        <span class="badge d-inline-flex align-items-center gap-1" style="background: #C62E65; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem;">
                            <i class="bi bi-person-fill" style="font-size: 0.6rem;"></i> User
                        </span>
                    <?php elseif ($isAgent): ?>
                        <span class="badge d-inline-flex align-items-center gap-1" style="background: #7FC8F8; color: #1e293b; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem;">
                            <i class="bi bi-headset" style="font-size: 0.6rem;"></i> Agent
                        </span>
                    <?php elseif ($isAdmin): ?>
                        <span class="badge d-inline-flex align-items-center gap-1" style="background: #F6AE2D; color: #1e293b; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem;">
                            <i class="bi bi-shield-fill-check" style="font-size: 0.6rem;"></i> Admin
                        </span>
                    <?php elseif ($isSuperAdmin): ?>
                        <span class="badge d-inline-flex align-items-center gap-1" style="background: black; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem;">
                            <i class="bi bi-person-gear" style="font-size: 0.6rem;"></i> Super Admin
                        </span>
                    <?php endif; ?>

                    <!-- Department Badge (for all users except maybe superadmin) -->
                    <?php if (!empty($UserDept)): ?>
                        <span class="badge d-inline-flex align-items-center gap-1 ms-1" style="background: rgba(255,255,255,0.2); color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem;">
                            <i class="bi bi-building" style="font-size: 0.6rem;"></i> 
                            <?= htmlspecialchars(strlen($UserDept) > 20 ? substr($UserDept, 0, 20) . '...' : $UserDept) ?>
                        </span>
                    <?php endif; ?>
                    
                    <!-- Division Badge if available -->
                    <?php if (!empty($UserDivisionId) && $UserDivisionId > 0): 
                        // You might want to fetch division name here
                    ?>
                        <span class="badge ms-1" style="background: rgba(255,255,255,0.1); color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.65rem;">
                            <i class="bi bi-diagram-3"></i> Div
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Role Switcher (desktop, only shown when user has multiple roles) -->
                <?php
                $_desktopRoles = getUserRoles();
                if (count($_desktopRoles) > 1):
                    $_desktopActive = getActiveRole();
                    $_desktopCSRF   = $_SESSION['csrf_token'] ?? '';
                    $_desktopIcons  = ['superadmin'=>'bi-person-gear','admin'=>'bi-shield-fill-check','agent'=>'bi-headset','user'=>'bi-person-fill'];
                ?>
                <div class="dropdown">
                    <button class="btn btn-sm d-flex align-items-center gap-1"
                            style="background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 20px; padding: 0.3rem 0.8rem;"
                            data-bs-toggle="dropdown" aria-expanded="false" title="Switch role">
                        <i class="bi bi-arrow-left-right" style="font-size: 0.75rem;"></i>
                        <span style="font-size: 0.8rem;"><?= htmlspecialchars(ucfirst($_desktopActive)) ?></span>
                        <i class="bi bi-chevron-down" style="font-size: 0.65rem; opacity: 0.8;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><h6 class="dropdown-header">Switch Role</h6></li>
                        <?php foreach ($_desktopRoles as $_dRole):
                            $_dIcon = $_desktopIcons[$_dRole] ?? 'bi-person-fill';
                        ?>
                        <li>
                            <form method="POST" action="/pspf_crm/api/switch_role.php">
                                <input type="hidden" name="role" value="<?= htmlspecialchars($_dRole) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_desktopCSRF) ?>">
                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2<?= $_dRole === $_desktopActive ? ' active fw-semibold' : '' ?>">
                                    <i class="bi <?= $_dIcon ?>"></i>
                                    <?= htmlspecialchars(ucfirst($_dRole)) ?>
                                    <?php if ($_dRole === $_desktopActive): ?><i class="bi bi-check2 ms-auto"></i><?php endif ?>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Profile Avatar with Role Color and Department Tooltip -->
                <a href="/pspf_crm/api/settings/profile.php" class="text-decoration-none" title="<?= htmlspecialchars($UserDept) ?>">
                    <span class="role-avatar" style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: <?php
                        if ($isSuperAdmin) echo 'black';
                        elseif ($isAdmin) echo '#F6AE2D';
                        elseif ($isAgent) echo '#7FC8F8';
                        else echo '#C62E65';
                    ?>; color: <?= ($isAdmin || $isAgent) ? '#1e293b' : 'white' ?>; border: 2px solid rgba(255,255,255,0.3);">
                        <i class="bi <?= $iconClass ?>" style="font-size: 1.2rem;"></i>
                    </span>
                </a>

                <!-- Logout Button -->
                <a href="/pspf_crm/api/signin/logout.php" class="btn btn-outline-light btn-sm" style="border-radius: 30px; padding: 0.4rem 1rem;">
                    Logout <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Add this CSS for better badge styling -->
<style>
/* Enhanced badge styles */
.navbar .badge {
    font-weight: 500;
    letter-spacing: 0.3px;
    transition: all 0.2s;
    border: 1px solid rgba(255,255,255,0.1);
}

.navbar .badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Role badge colors */
.role-badge-user {
    background: #C62E65;
    color: white;
}

.role-badge-agent {
    background: #7FC8F8;
    color: #1e293b;
}

.role-badge-admin {
    background: #F6AE2D;
    color: #1e293b;
}

.role-badge-superadmin {
    background: black;
    color: white;
}

/* Department badge */
.dept-badge {
    background: rgba(255,255,255,0.2);
    color: white;
    backdrop-filter: blur(4px);
}

/* Avatar hover effect */
.role-avatar {
    transition: all 0.2s;
}

.role-avatar:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

/* Mobile dropdown enhancements */
@media (max-width: 991.98px) {
    .dropdown-header i {
        margin-right: 0.5rem;
        opacity: 0.7;
    }
    
    .dropdown-menu-end .dropdown-header.text-muted {
        color: rgba(255,255,255,0.7) !important;
        font-size: 0.8rem;
        padding: 0.5rem 1rem;
    }
    
    .dropdown-menu-end .dropdown-header:first-child {
        font-weight: 600;
        font-size: 0.9rem;
    }
}
</style>

<script>
// Mobile menu enhancements
document.addEventListener('DOMContentLoaded', function() {
    // Close mobile menu when clicking a link
    const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
    const navbarToggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.querySelector('.navbar-collapse');
    
    if (navbarToggler && navbarCollapse) {
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    // Don't close if it's a dropdown toggle
                    if (!link.classList.contains('dropdown-toggle')) {
                        navbarToggler.click();
                    }
                }
            });
        });
    }
    
    // Handle dropdowns on mobile
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            if (window.innerWidth < 992) {
                e.preventDefault();
                const dropdownMenu = this.nextElementSibling;
                
                // Close other open dropdowns
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    if (menu !== dropdownMenu) {
                        menu.classList.remove('show');
                    }
                });
                
                // Toggle current dropdown
                dropdownMenu.classList.toggle('show');
            }
        });
    });
    
    // Close dropdowns when tapping outside
    document.addEventListener('click', function(e) {
        if (window.innerWidth < 992) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        }
    });
    
    // Handle orientation change
    window.addEventListener('orientationchange', function() {
        if (window.innerWidth >= 992) {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
    
    // Handle resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth >= 992) {
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        }, 250);
    });
    
    // Highlight active page
    const currentPath = window.location.pathname;
    const pageName = currentPath.split('/').pop();
    
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.includes(pageName) && pageName !== '') {
            link.classList.add('active');
            link.closest('.nav-item')?.classList.add('active');
        }
    });
});

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close mobile menu if open
        const navbarCollapse = document.querySelector('.navbar-collapse.show');
        const navbarToggler = document.querySelector('.navbar-toggler');
        
        if (navbarCollapse && navbarCollapse.classList.contains('show')) {
            navbarToggler.click();
        }
        
        // Close all dropdowns
        document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
            menu.classList.remove('show');
        });
    }
});
</script>