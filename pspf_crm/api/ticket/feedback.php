<?php
require_once '../db.php';
//feedback.php

$token_safe = $_GET['token'] ?? '';

if (!$token_safe) {
    $error_message = "Invalid or missing feedback link.";
    $show_form = false;
} else {
    // Check token validity
    $stmt = $conn->prepare("
        SELECT ft.ticket_id, t.title
        FROM feedback_tokens ft
        JOIN tickets t ON t.id = ft.ticket_id
        WHERE ft.token = ? AND ft.used = 0 AND ft.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token_safe);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $stmt->close();

    if (!$ticket) {
        $error_message = "Feedback already submitted or link expired.";
        $show_form = false;
    } else {
        $ticket_id = $ticket['ticket_id'];
        $ticket_title = $ticket['title'];
        $show_form = true;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ticket Feedback</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../style5.css">
  <link rel="stylesheet" href="../style4.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
  <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
    .star { font-size: 30px; cursor: pointer; color: #ccc; }
    .star.selected { color: gold; }
    /* ============================= */
    /* FULL SCREEN BACKGROUND */
    /* ============================= */
    .feedback-page {
        margin: 0;
        padding: 0;
        min-height: 100vh;

        /* Background Image */
        background: url('../uploads/bluetree.png') no-repeat center center fixed;
        background-size: cover;

        /* Centering */
        display: flex;
        justify-content: center;
        align-items: center;

        font-family: 'Segoe UI', sans-serif;
    }

    /* ============================= */
    /* OPTIONAL DARK OVERLAY */
    /* ============================= */
    .feedback-page::before {
        content: "";
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45); /* Adjust darkness */
        z-index: 0;
    }

    /* ============================= */
    /* CARD STYLING */
    /* ============================= */
    .feedback-card {
        position: relative;
        z-index: 1; /* Above overlay */

        width: 400px;
        max-width: 90%;
        padding: 30px;
        border-radius: 12px;

        background: #ffffff;
        box-shadow: 0 10px 35px rgba(0, 0, 0, 0.2);
    }

    </style>
</head>
<body class="feedback-page">

<div class="container mt-5">
    <div class="card shadow mx-auto" style="max-width:500px;">
        <div class="feedback-card">
            <?php if ($show_form): ?>
                <h4>Feedback for Ticket #<?= htmlspecialchars($ticket_id) ?></h4>
                <p><strong><?= htmlspecialchars($ticket_title) ?></strong></p>

                <div id="starRating" class="mb-3">
                    <span class="star" data-value="1">★</span>
                    <span class="star" data-value="2">★</span>
                    <span class="star" data-value="3">★</span>
                    <span class="star" data-value="4">★</span>
                    <span class="star" data-value="5">★</span>
                </div>

                <textarea id="comment" class="form-control mb-3" placeholder="Tell us why you gave this rating..."></textarea>

                <button class="btn btn-primary" onclick="submitFeedback()">Submit Feedback</button>

                <div id="responseMsg" class="mt-3"></div>

            <?php else: ?>
                <h4>Feedback Not Available</h4>
                <p class="text-muted"><?= htmlspecialchars($error_message) ?></p>
                <a href="/pspf_crm/api/signin/index.php" class="btn btn-primary mt-3">Go to Login</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($show_form): ?>
<script>
let selectedRating = 0;

// Handle star selection
document.querySelectorAll('.star').forEach(star => {
    star.addEventListener('click', function () {
        selectedRating = parseInt(this.getAttribute('data-value'));
        document.querySelectorAll('.star').forEach(s => s.classList.remove('selected'));
        for (let i = 0; i < selectedRating; i++) {
            document.querySelectorAll('.star')[i].classList.add('selected');
        }
    });
});

function submitFeedback() {
    const commentEl = document.getElementById('comment');
    const responseEl = document.getElementById('responseMsg');
    const btn = document.querySelector('button');

    if (selectedRating === 0) {
        alert("Please select a rating.");
        return;
    }

    // Disable the form immediately
    document.querySelectorAll('.star').forEach(s => s.style.pointerEvents = 'none');
    commentEl.disabled = true;
    btn.disabled = true;

    fetch('/pspf_crm/api/ticket/submit_feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            token: "<?= htmlspecialchars($token_safe) ?>",
            rating: selectedRating,
            comment: commentEl.value
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Replace the form with a Thank You message
            document.querySelector('.feedback-card').innerHTML = `
                <h3 class="text-success">Thank you for your feedback!</h3>
                <p>Your input has been recorded successfully.</p>
            `;
            // Redirect after 2 seconds
            setTimeout(() => {
                window.location.href = '/pspf_crm/api/signin/index.php';
            }, 2000);
        } else {
            // Show error and re-enable form
            responseEl.innerHTML = `<div class="alert alert-danger text-center">${data.message}</div>`;
            document.querySelectorAll('.star').forEach(s => s.style.pointerEvents = '');
            commentEl.disabled = false;
            btn.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        responseEl.innerHTML = `<div class="alert alert-danger text-center">An error occurred. Please try again.</div>`;
        document.querySelectorAll('.star').forEach(s => s.style.pointerEvents = '');
        commentEl.disabled = false;
        btn.disabled = false;
    });
}
</script>

<?php endif; ?>

</body>
</html>
