"""Ticket views — user/agent/admin dashboards, submission, tracking, status."""

from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.db.models import Count, Q
from django.http import JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from accounts.roles import require_roles

from .forms import FeedbackForm, TicketForm, TicketStatusForm
from .models import Ticket, TicketStatusLog
from .services import change_status, create_ticket, notify_ticket_once

OPEN_STATES = ["Open", "In Progress", "On Hold", "Escalated"]


# ---------------------------------------------------------------------------
# User side
# ---------------------------------------------------------------------------
@login_required
def user_dashboard(request):
    tickets = Ticket.objects.filter(created_by=request.user.username)
    context = {
        "tickets": tickets,
        "open_count": tickets.filter(status__in=OPEN_STATES).count(),
        "resolved_count": tickets.filter(status__in=["Resolved", "Closed"]).count(),
        "total_count": tickets.count(),
    }
    return render(request, "tickets/user_dashboard.html", context)


@login_required
def query_view(request):
    """Ticket submission form (query.php + submit_query2.php)."""
    form = TicketForm(request.POST or None, request.FILES or None)
    if request.method == "POST" and form.is_valid():
        ticket = create_ticket(form, request.user.username)
        return redirect("tickets:success", ticket_id=ticket.id)
    return render(request, "tickets/query.html", {"form": form})


@login_required
def ticket_success(request, ticket_id):
    ticket = get_object_or_404(Ticket, pk=ticket_id)
    # Fire the assignment/confirmation email exactly once.
    notify_ticket_once(ticket)
    return render(request, "tickets/success.html", {"ticket": ticket})


@login_required
def ticket_track(request):
    """Track a ticket by id and show its status history (ticket_track.php)."""
    ticket = None
    logs = []
    ticket_id = request.GET.get("ticket_id")
    if ticket_id:
        ticket = Ticket.objects.filter(pk=ticket_id).first()
        if ticket:
            logs = ticket.status_logs.all()
        else:
            messages.warning(request, f"No ticket found with id {ticket_id}.")
    return render(request, "tickets/track.html", {"ticket": ticket, "logs": logs})


@login_required
def ticket_detail(request, ticket_id):
    ticket = get_object_or_404(Ticket, pk=ticket_id)
    return render(
        request,
        "tickets/detail.html",
        {"ticket": ticket, "logs": ticket.status_logs.all()},
    )


@login_required
@require_POST
def submit_feedback(request, ticket_id):
    ticket = get_object_or_404(Ticket, pk=ticket_id)
    form = FeedbackForm(request.POST)
    if form.is_valid():
        fb = form.save(commit=False)
        fb.ticket = ticket
        fb.submitted_by = request.user.username
        fb.save()
        messages.success(request, "Thanks for your feedback.")
    else:
        messages.error(request, "Could not save feedback.")
    return redirect("tickets:detail", ticket_id=ticket.id)


# ---------------------------------------------------------------------------
# Agent side
# ---------------------------------------------------------------------------
@require_roles("agent")
def agent_dashboard(request):
    """Agent sees tickets routed to their division."""
    division = request.user.division
    tickets = Ticket.objects.filter(division=division) if division else Ticket.objects.none()
    context = {
        "division": division,
        "tickets": tickets,
        "status_form": TicketStatusForm(),
        "open_count": tickets.filter(status__in=OPEN_STATES).count(),
        "resolved_count": tickets.filter(status__in=["Resolved", "Closed"]).count(),
    }
    return render(request, "tickets/agent_dashboard.html", context)


# ---------------------------------------------------------------------------
# Admin side
# ---------------------------------------------------------------------------
@require_roles("admin", "superadmin")
def admin_dashboard(request):
    tickets = Ticket.objects.all()
    by_status = (
        tickets.values("status").annotate(n=Count("id")).order_by("-n")
    )
    context = {
        "tickets": tickets[:200],
        "total": tickets.count(),
        "by_status": by_status,
        "open_count": tickets.filter(status__in=OPEN_STATES).count(),
        "status_form": TicketStatusForm(),
    }
    return render(request, "tickets/admin_dashboard.html", context)


@login_required
@require_POST
def change_status_view(request, ticket_id):
    """Update a ticket's status (agents/admins). Ports change_status.php."""
    active = request.session.get("active_role", "user")
    if active not in {"agent", "admin", "superadmin"}:
        messages.error(request, "You are not allowed to change ticket status.")
        return redirect("accounts:dashboard")

    ticket = get_object_or_404(Ticket, pk=ticket_id)
    form = TicketStatusForm(request.POST)
    if form.is_valid():
        if change_status(ticket, form.cleaned_data["status"], request.user.username):
            messages.success(request, f"Ticket #{ticket.id} status updated.")
        else:
            messages.info(request, "Status unchanged.")
    else:
        messages.error(request, "Invalid status.")

    back = request.POST.get("next") or "accounts:dashboard"
    if back.startswith("/"):
        return redirect(back)
    return redirect(back)


# ---------------------------------------------------------------------------
# JSON endpoints (ported AJAX)
# ---------------------------------------------------------------------------
@login_required
def ticket_status_summary(request):
    """JSON status counts (ticket_status_summary.php)."""
    qs = Ticket.objects.values("status").annotate(n=Count("id"))
    return JsonResponse({row["status"]: row["n"] for row in qs})
