<?php
session_start();
// select_role.php
if (!isset($_SESSION['pending_roles'])) {
    header("Location: index.php");
    exit;
}

// it_officer / it_director are permissions, not selectable personas — never offer them here.
$NON_SELECTABLE_ROLES = ['it_officer', 'it_director'];
$roles = array_values(array_diff($_SESSION['pending_roles'], $NON_SELECTABLE_ROLES));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen = $_POST['role'] ?? '';
    // Only allow choosing a role the user actually has AND that is selectable.
    if (!in_array($chosen, $roles, true)) {
        header("Location: select_role.php");
        exit;
    }
    $_SESSION['active_role'] = $chosen;
    unset($_SESSION['pending_roles']);

    header("Location: ../dashboard.php");
    exit;
}

// Map roles to Bootstrap Icons
$roleIcons = [
    'admin' => 'bi-shield-check',
    'user' => 'bi-person',
    'superadmin' => 'bi-person-gear',
    'agent' => 'bi-headset',
    // Add more role-icon mappings as needed
];

// Function to get icon for role
function getRoleIcon($role, $roleIcons) {
    $roleLower = strtolower($role);
    if (isset($roleIcons[$roleLower])) {
        return $roleIcons[$roleLower];
    }
    
    // Default icons based on role keywords
    if (strpos($roleLower, 'admin') !== false) return 'bi-shield-check';
    if (strpos($roleLower, 'superadmin') !== false) return 'bi-person-gear';
    
    return 'bi-person'; // Default icon
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Role - PSPF CRM</title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="loginstyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
    <style>
     
        .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }
        
        .roles-container {
            margin: 20px 0;
        }
        
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
        }
        
        .role-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 18px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #f8f9fa;
            position: relative;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .role-card:hover {
            transform: translateY(-3px);
            border-color: #406997;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            background: #fff;
        }
        
        .role-card.selected {
            border-color: #fff;
            background: linear-gradient(135deg, #406997 0%, #3D5C80 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(57, 73, 171, 0.2);
        }
        
        .role-card.selected .role-icon {
            color: white;
            transform: scale(1.05);
        }
        
        .role-icon {
            font-size: 2rem;
            color: #406997;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }
        
        .role-name {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
            line-height: 1.2;
        }
        
        .role-description {
            font-size: 0.8rem;
            color: #666;
            line-height: 1.3;
            max-height: 40px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        
        .role-card.selected .role-description {
            color: rgba(255,255,255,0.9);
        }
        
        .radio-hidden {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .button-container {
            margin-top: 20px;
            text-align: center;
        }
        
        .continue-btn {
            background: linear-gradient(135deg, #406997 0%, #3D5C80 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
            min-width: 150px;
        }
        
        .continue-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(57, 73, 171, 0.2);
        }
        
        .continue-btn:disabled {
            background: #cccccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .back-link {
            text-align: center;
            margin-top: 15px;
        }
        
        .back-link a {
            color: #406997;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }
        
        .back-link a:hover {
            color: #3D5C80;
            text-decoration: underline;
        }
        
        .instruction {
            text-align: center;
            color: #666;
            margin: 10px 0 15px 0;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        .user-info {
            background: #f0f4ff;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 4px solid #406997;
        }
        
        .user-name {
            font-weight: 600;
            color: #3D5C80;
            font-size: 1rem;
        }
        
        .user-email {
            color: #dc1e4a;
            font-size: 0.85rem;
        }
        
        .role-count {
            display: inline-block;
            background: #406997;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-bottom: 15px;
            margin-left: 10px;
        }
        
        .header-info {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .role-selection-container {
                flex-direction: column;
                max-height: none;
                min-height: 100vh;
            }
            
            .left-panel {
                padding: 20px;
                min-height: 200px;
            }
            
            .right-panel {
                padding: 20px;
                max-height: none;
                overflow: visible;
            }
            
            .roles-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 10px;
                max-height: 350px;
            }
            
            .role-card {
                padding: 15px 10px;
                min-height: 120px;
            }
            
            .role-icon {
                font-size: 1.8rem;
                margin-bottom: 8px;
            }
            
            .role-name {
                font-size: 0.9rem;
            }
            
            .role-description {
                font-size: 0.75rem;
                max-height: 35px;
            }
            
            .welcome {
                font-size: 1.8rem;
            }
            
            .name-headline {
                font-size: 1.2rem;
            }
        }
        
        @media (max-height: 700px) {
            .roles-grid {
                max-height: 300px;
            }
            
            .role-card {
                min-height: 120px;
                padding: 15px 10px;
            }
        }
        
        /* Compact view for many roles */
        .compact-view .roles-grid {
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
        }
        
        .compact-view .role-card {
            padding: 15px 12px;
            min-height: 130px;
        }
        
        .compact-view .role-icon {
            font-size: 1.8rem;
        }
        
        .compact-view .role-name {
            font-size: 0.95rem;
        }
        
        .compact-view .role-description {
            font-size: 0.75rem;
            line-clamp: 2;
        }
        
        .scroll-hint {
            text-align: center;
            color: #888;
            font-size: 0.8rem;
            margin-top: 5px;
            display: none;
        }
        
        .roles-grid.scrollable {
            padding-right: 5px;
        }
        
        .roles-grid.scrollable ~ .scroll-hint {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="overlay">
                <img src="../uploads/pspflogo2.png" alt="Company Logo" class="logo-img" />
                <h1 class="welcome">CHOOSE ROLE</h1>
                <span class="tagline">Select your role to continue</span>
                <div class="name-headline">CUSTOMER RELATIONSHIP MANAGEMENT</div>
                <p style="font-size: 0.8rem;">Copyright &copy; <?= date('Y') ?> - All Rights Reserved to PSPF ICT</p>
            </div>
        </div>
        
        <div class="right-panel">
                <h2 class="login-title">Select Your Role</h2>
                <div class="subtitle">You have access to multiple roles. Please select one to continue:</div>
                
                <?php if (isset($_SESSION['user'])): ?>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($_SESSION['user']['username'] ?? 'User') ?></div>
                        <div class="user-email"><?= htmlspecialchars($_SESSION['user']['email'] ?? '') ?></div>
                    </div>
                <?php endif; ?>
                
                <div class="header-info">
                    <div class="subtitle" style="margin: 0;">Available Roles:</div>
                    <div class="role-count"><?= count($roles) ?> role(s)</div>
                </div>
                
                <form method="POST" action="" id="roleForm">
                    <div class="roles-container">
                        <div class="roles-grid <?= count($roles) > 6 ? 'compact-view' : '' ?>">
                            <?php foreach ($roles as $role): 
                                $iconClass = getRoleIcon($role, $roleIcons);
                                $roleDisplay = htmlspecialchars(ucfirst($role));
                            ?>
                                <label class="role-card" for="role_<?= htmlspecialchars($role) ?>">
                                    <input type="radio" 
                                           name="role" 
                                           value="<?= htmlspecialchars($role) ?>" 
                                           id="role_<?= htmlspecialchars($role) ?>" 
                                           class="radio-hidden" 
                                           required>
                                    <div class="role-icon">
                                        <i class="bi <?= $iconClass ?>"></i>
                                    </div>
                                    <div class="role-name"><?= $roleDisplay ?></div>
                                    <div class="role-description">
                                        <?php 
                                        // Generate description based on role
                                        switch(strtolower($role)) {
                                            case 'superadmin':
                                                echo 'Full system access';
                                                break;
                                            case 'user':
                                                echo 'Standard access';
                                                break;
                                            case 'admin':
                                                echo 'departmental administrator';
                                                break;
                                            case 'agent':
                                                echo 'Customer support';
                                                break;
                                            default:
                                                echo ucfirst($role) . ' access';
                                        }
                                        ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="scroll-hint">Scroll to see more roles</div>
                    </div>
                    
                    <p class="instruction">Click on a role card to select it</p>
                    
                    <div class="button-container">
                        <button type="submit" class="continue-btn" id="continueBtn" disabled>
                            <span class="btn-text">Continue</span>
                            <span class="btn-loading" style="display: none;">
                                <i class="bi bi-arrow-clockwise"></i> Loading...
                            </span>
                        </button>
                    </div>
                </form>
                
                <div class="back-link">
                    <a href="./index.php"><i class="bi bi-arrow-left"></i> Back to Login</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const roleCards = document.querySelectorAll('.role-card');
            const continueBtn = document.getElementById('continueBtn');
            const btnText = continueBtn.querySelector('.btn-text');
            const btnLoading = continueBtn.querySelector('.btn-loading');
            const rolesGrid = document.querySelector('.roles-grid');
            
            // Check if grid needs scrolling
            if (rolesGrid.scrollHeight > rolesGrid.clientHeight) {
                rolesGrid.classList.add('scrollable');
            }
            
            // Add click event to role cards
            roleCards.forEach(card => {
                card.addEventListener('click', function() {
                    // Remove selected class from all cards
                    roleCards.forEach(c => c.classList.remove('selected'));
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                    
                    // Check the radio button inside
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        radio.focus();
                    }
                    
                    // Enable continue button
                    continueBtn.disabled = false;
                    
                    // Add subtle animation
                    this.style.transform = 'scale(1.02)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
                
                // Keyboard support
                card.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
                
                // Make cards keyboard accessible
                card.setAttribute('tabindex', '0');
                card.setAttribute('role', 'button');
                card.setAttribute('aria-label', `Select ${card.querySelector('.role-name').textContent} role`);
            });
            
            // Form submission
            document.getElementById('roleForm').addEventListener('submit', function(e) {
                const selectedRole = document.querySelector('input[name="role"]:checked');
                if (!selectedRole) {
                    e.preventDefault();
                    alert('Please select a role to continue.');
                    return false;
                }
                
                // Show loading state
                btnText.style.display = 'none';
                btnLoading.style.display = 'inline';
                continueBtn.disabled = true;
                
                return true;
            });
            
            // Auto-select first role if only one
            if (roleCards.length === 1) {
                setTimeout(() => {
                    roleCards[0].click();
                }, 300);
            }
            
            // Auto-focus on first role for keyboard users
            if (roleCards.length > 0) {
                roleCards[0].focus();
            }
            
            // Compact view for many roles
            if (roleCards.length > 6) {
                document.body.classList.add('many-roles');
            }
            
            // Add some visual feedback
            setTimeout(() => {
                roleCards.forEach((card, index) => {
                    setTimeout(() => {
                        card.style.opacity = '0.7';
                        card.style.transform = 'translateY(5px)';
                        setTimeout(() => {
                            card.style.opacity = '1';
                            card.style.transform = 'translateY(0)';
                        }, 100);
                    }, index * 50);
                });
            }, 200);
        });
    </script>
</body>
</html>