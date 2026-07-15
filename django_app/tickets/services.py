"""Ticket domain logic shared by views (routing, status logging, notifications)."""

from django.conf import settings
from django.core.mail import send_mail
from django.db import transaction
from django.utils import timezone

from accounts.models import User

from .models import Ticket, TicketStatusLog


def assignees_for_division(division):
    """Emails of every user in the routed division (submit_query2.php logic)."""
    if not division:
        return []
    return list(
        User.objects.filter(division=division)
        .exclude(email="")
        .values_list("email", flat=True)
    )


@transaction.atomic
def create_ticket(form, username):
    """Create a ticket, route it to its division and log the initial 'Open' status."""
    ticket = form.save(commit=False)
    ticket.created_by = username
    ticket.status = "Open"
    ticket.query_date = timezone.now()

    division = form.cleaned_data.get("query_type")
    ticket.division = division
    ticket.query_type = str(division.id) if division else ""
    ticket.assigned_to = ", ".join(assignees_for_division(division))
    ticket.save()

    TicketStatusLog.objects.create(
        ticket=ticket, old_status=None, new_status="Open", changed_by=username
    )
    return ticket


def notify_ticket_once(ticket):
    """One-time confirmation/assignment email (mirrors notified_at guard).

    Returns True if this call won the race and sent the notification.
    """
    claimed = Ticket.objects.filter(pk=ticket.pk, notified_at__isnull=True).update(
        notified_at=timezone.now()
    )
    if not claimed:
        return False

    recipients = [e for e in ticket.assigned_to.split(",") if e.strip()]
    if recipients:
        send_mail(
            subject=f"[Ticket #{ticket.pk}] {ticket.title}",
            message=(
                f"A new ticket has been logged and assigned to your division.\n\n"
                f"Title: {ticket.title}\nPriority: {ticket.priority}\n"
                f"Description:\n{ticket.description}\n"
            ),
            from_email=settings.DEFAULT_FROM_EMAIL,
            recipient_list=[r.strip() for r in recipients],
            fail_silently=True,
        )
    return True


@transaction.atomic
def change_status(ticket, new_status, changed_by):
    """Update ticket status and append a status-log row."""
    old = ticket.status
    if old == new_status:
        return False
    ticket.status = new_status
    ticket.last_updated_by = changed_by
    ticket.save(update_fields=["status", "last_updated_by", "updated_at"])
    TicketStatusLog.objects.create(
        ticket=ticket, old_status=old, new_status=new_status, changed_by=changed_by
    )
    return True
