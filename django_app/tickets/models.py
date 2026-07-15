"""
Helpdesk tickets.

Ports the `tickets` and `ticket_status_logs` tables. Faithful to the legacy
columns: `created_by` stores the *username* string (not a FK), tickets are
routed to a division, and every status change is logged.
"""

from django.conf import settings
from django.db import models
from django.utils import timezone


class Ticket(models.Model):
    STATUS_CHOICES = [
        ("Open", "Open"),
        ("In Progress", "In Progress"),
        ("On Hold", "On Hold"),
        ("Escalated", "Escalated"),
        ("Resolved", "Resolved"),
        ("Closed", "Closed"),
    ]
    PRIORITY_CHOICES = [
        ("Low", "Low"),
        ("Medium", "Medium"),
        ("High", "High"),
        ("Urgent", "Urgent"),
    ]

    title = models.CharField(max_length=255)
    member_type = models.CharField(max_length=100, blank=True, default="")
    region = models.CharField(max_length=100, blank=True, default="")
    source = models.CharField(max_length=100, blank=True, default="")
    # query_type carries the selected division id in the legacy form.
    query_type = models.CharField(max_length=100, blank=True, default="")
    description = models.TextField()
    priority = models.CharField(max_length=20, choices=PRIORITY_CHOICES, default="Medium")
    phone_number = models.CharField(max_length=20, blank=True, default="")
    query_date = models.DateTimeField(default=timezone.now)
    # Legacy stores the submitting user's *username* here.
    created_by = models.CharField(max_length=150)
    status = models.CharField(max_length=30, choices=STATUS_CHOICES, default="Open")
    attachment_path = models.CharField(max_length=500, blank=True, null=True)
    # Comma-separated list of assignee emails, as in submit_query2.php.
    assigned_to = models.TextField(blank=True, default="")
    division = models.ForeignKey(
        "orgunits.Division",
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        db_column="division_id",
        related_name="tickets",
    )
    last_updated_by = models.CharField(max_length=255, blank=True, null=True)
    updated_at = models.DateTimeField(auto_now=True)
    # One-time notification guard (migration 001_add_ticket_notified_at.sql).
    notified_at = models.DateTimeField(null=True, blank=True)

    class Meta:
        db_table = "tickets"
        ordering = ["-query_date"]

    def __str__(self) -> str:
        return f"#{self.pk} {self.title}"


class TicketStatusLog(models.Model):
    """Append-only audit trail of ticket status transitions."""

    ticket = models.ForeignKey(
        Ticket,
        on_delete=models.CASCADE,
        db_column="ticket_id",
        related_name="status_logs",
    )
    old_status = models.CharField(max_length=30, null=True, blank=True)
    new_status = models.CharField(max_length=30)
    changed_by = models.CharField(max_length=150)
    changed_at = models.DateTimeField(default=timezone.now)

    class Meta:
        db_table = "ticket_status_logs"
        ordering = ["-changed_at"]

    def __str__(self) -> str:
        return f"{self.ticket_id}: {self.old_status} -> {self.new_status}"


class TicketFeedback(models.Model):
    """User feedback / rating captured after resolution (feedback.php)."""

    ticket = models.ForeignKey(
        Ticket,
        on_delete=models.CASCADE,
        db_column="ticket_id",
        related_name="feedback",
    )
    rating = models.PositiveSmallIntegerField(default=0)
    comment = models.TextField(blank=True, default="")
    submitted_by = models.CharField(max_length=150, blank=True, default="")
    submitted_at = models.DateTimeField(default=timezone.now)

    class Meta:
        db_table = "ticket_feedback"
        ordering = ["-submitted_at"]
