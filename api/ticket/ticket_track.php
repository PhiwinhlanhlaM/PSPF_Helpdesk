<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Track your ticket - PSPF CRM</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../style5.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
  <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* Modal */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.6);
  display: flex; align-items: center; justify-content: center;
}
.modal {
  background: #fff; padding: 25px; width: 400px;
  border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.2);
}

.timeline { margin-top: 30px; }
.timeline-item {
  display: flex; margin-bottom: 20px;
}
.timeline-dot {
  width: 14px; height: 14px; border-radius: 50%;
  background: #3498db; margin-right: 15px; margin-top: 5px;
}
.timeline-content {
  background: #f8f9fa; padding: 10px 15px;
  border-radius: 6px; width: 100%;
}

.hidden { display: none; }
/* Update modal styles to show tracking modal initially */
#ticketModal {
  display: flex; /* Show by default */
}

#ticketModal.hidden,
#feedbackModal.hidden {
  display: none;
}
</style>
</head>
<body>

<!-- Floating Modal -->
<div class="modal-overlay" id="ticketModal">
  <div class="modal">
    <h3>Track Your Ticket</h3>
    <input type="text" id="ticketNo" placeholder="Enter Ticket Number" class="form-control">
    <button onclick="trackTicket()" class="btn btn-primary" style="margin-top:10px">Track</button>
  </div>
</div>

<!-- Ticket Content -->
<div class="container hidden" id="ticketContent">
  <h2 id="ticketSubject"></h2>
  <p id="ticketDesc"></p>

  <div class="timeline" id="timeline"></div>
</div>

<!-- Feedback Modal -->
<div class="modal-overlay hidden" id="feedbackModal">
  <div class="modal">
    <h3>Service Feedback</h3>
    <label>Rating (1-5)</label>
    <input type="number" id="rating" min="1" max="5" required>
    <label>Comment</label>
    <textarea id="comment" required></textarea>
    <button onclick="submitFeedback()" class="btn btn-success">Submit</button>
  </div>
</div>
<script>
let currentTicketId = null;

function trackTicket() {
  const ticketNo = document.getElementById('ticketNo').value.trim();
  if (!ticketNo) return alert('Please enter a ticket number.');

  fetch('./get_ticket_progress.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ticket_no: ticketNo })
  })
  .then(res => res.json())
  .then(data => {
    if (!data.success) return alert(data.message);

    // Hide modal, show content
    document.getElementById('ticketModal').classList.add('hidden');
    document.getElementById('ticketContent').classList.remove('hidden');

    // Set ticket details
    document.getElementById('ticketSubject').innerText = data.ticket.subject;
    document.getElementById('ticketDesc').innerText = data.ticket.description;
    
    // Store ticket ID for feedback
    currentTicketId = data.ticket.id;

    // Clear and rebuild timeline
    const timeline = document.getElementById('timeline');
    timeline.innerHTML = '';
    
    // Use DocumentFragment for better performance
    const fragment = document.createDocumentFragment();
    data.logs.forEach(log => {
      const item = document.createElement('div');
      item.className = 'timeline-item';
      item.innerHTML = `
        <div class="timeline-dot"></div>
        <div class="timeline-content">
          <strong>${log.new_status.replace('_',' ').toUpperCase()}</strong><br>
          <small>${log.changed_at}</small>
        </div>`;
      fragment.appendChild(item);
    });
    timeline.appendChild(fragment);

    // Show feedback modal if ticket is closed and no feedback given
    if (data.ticket.status === 'closed' && !data.feedback_given) {
      document.getElementById('feedbackModal').classList.remove('hidden');
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Failed to fetch ticket details. Please try again.');
  });
}

function submitFeedback() {
  const rating = parseInt(document.getElementById('rating').value);
  const comment = document.getElementById('comment').value.trim();
  
  // Validation
  if (!rating || rating < 1 || rating > 5) return alert('Enter a rating 1-5');
  if (!comment) return alert('Please write a comment.');
  if (!currentTicketId) return alert('No ticket selected.');

  fetch('api/submit_feedback.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      ticket_id: currentTicketId,
      rating: rating,
      comment: comment
    })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert('Thank you for your feedback');
      document.getElementById('feedbackModal').classList.add('hidden');
    } else {
      alert(data.message || 'Failed to submit feedback');
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Failed to submit feedback. Please try again.');
  });
}

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(modal => {
  modal.addEventListener('click', e => {
    if (e.target === modal) modal.classList.add('hidden');
  });
});

// Optional: Show tracking modal on page load
document.addEventListener('DOMContentLoaded', function() {
  // Check URL for ticket parameter
  const urlParams = new URLSearchParams(window.location.search);
  const ticketParam = urlParams.get('ticket');
  if (ticketParam) {
    document.getElementById('ticketNo').value = ticketParam;
    trackTicket();
  }
});

// Optional: Enter key support for ticket input
document.getElementById('ticketNo')?.addEventListener('keypress', function(e) {
  if (e.key === 'Enter') trackTicket();
});
</script>
</body>
</html>
