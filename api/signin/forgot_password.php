
<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in CRM</title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="loginstyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">

</head>
<body>
<?php include './loader.php'; ?>

<div class="container">
        <div class="left-panel">
            <div class="overlay">
            <img src="../uploads/pspflogo2.png" alt="Company Logo" class="logo-img" />
            <h1 class="welcome">Password Reset </h1>
            <span class="tagline">Reset your password quickly and securely.</span>
            <div class="name-headline">CUSTOMER RELATIONSHIP MANAGEMENT</div>
                <p>Copyright &copy; <?= date('Y') ?>  - All Rights Reserved to PSPF ICT</p>
            </div>
        </div>

    <div class="right-panel">
      
    <h2 class="login-title">Forgot Password</h2>

    <p class="login-paragraph">If you have lost your password or want to reset it, enter your email below:</p>

    <div class="alert-container"></div> <!-- alerts show here -->

    <form id="resetForm" method="POST" action="handle_forgot_password.php">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" id="email" class="form-control" required />
        </div>
        <button type="submit" class="login-btn">Reset Password</button>
    </form>

        <div class="divider">
            <span>  </span>
        </div>
        <div class="signup-link">
            Remembered your password? <a href="./index.php">Sign in</a>
        </div>
    </div>
</div>

<script>
document.getElementById("resetForm").addEventListener("submit", function (e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);

    fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(res => res.json())
    .then(data => {

        const alertPlaceholder = document.querySelector('.alert-container');
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + (data.success ? 'success' : 'danger');
        alert.innerText = data.message;

        alertPlaceholder.innerHTML = "";
        alertPlaceholder.appendChild(alert);

        setTimeout(() => alert.remove(), 5000);

        if (data.success) form.reset();
    })
    .catch(err => {
        console.error(err);
        alert("Something went wrong. Please try again later.");
    });
});

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>



