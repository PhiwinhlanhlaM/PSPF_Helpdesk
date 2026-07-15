"""IT Access views — submit / list / claim / approve.

Ports it_access/index.php, submit.php, list.php, claim.php, approve.php.
Access rules mirror the PHP (hasRole checks, not active role):
  * submit  — admin / superadmin
  * claim   — it_officer
  * approve/director sign-off — it_director
"""

from datetime import date

from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.db import transaction
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone
from django.views.decorators.http import require_POST

from accounts.roles import require_held_role

from .forms import ApprovalForm, ITAccessRequestForm
from .models import ITAccessRequest, ITRequestApproval, ITRequestSystem


def _next_ref_number() -> str:
    """Generate REQ-YYYY-NNNN (mirrors submit.php sequence-per-year)."""
    year = timezone.now().year
    count = ITAccessRequest.objects.filter(submitted_at__year=year).count()
    return f"REQ-{year}-{count + 1:04d}"


@login_required
def index(request):
    """Landing page listing the requests relevant to the current user."""
    requests = ITAccessRequest.objects.all()[:100]
    roles = set(request.user.role_names())
    return render(
        request,
        "it_access/index.html",
        {
            "requests": requests,
            "can_submit": bool(roles & {"admin", "superadmin"}),
            "can_claim": "it_officer" in roles,
            "can_direct": "it_director" in roles,
        },
    )


@require_held_role("admin", "superadmin")
def submit(request):
    form = ITAccessRequestForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            req = form.save(commit=False)
            req.ref_number = _next_ref_number()
            req.submitted_by = request.user
            req.status = "new"
            req.save()

            for system_id in form.cleaned_data["systems"]:
                ITRequestSystem.objects.create(request=req, system_id=system_id)

            ITRequestApproval.objects.create(
                request=req,
                step_role="manager",
                approver=request.user,
                action="approved",
                sig_kind="typed",
                sig_data=form.cleaned_data["manager_signature"],
            )
        messages.success(request, f"Request {req.ref_number} submitted.")
        return redirect("it_access:detail", pk=req.pk)
    return render(request, "it_access/submit.html", {"form": form})


@login_required
def detail(request, pk):
    req = get_object_or_404(ITAccessRequest, pk=pk)
    roles = set(request.user.role_names())
    return render(
        request,
        "it_access/detail.html",
        {
            "req": req,
            "systems": req.systems.all(),
            "approvals": req.approvals.all(),
            "approval_form": ApprovalForm(),
            "can_claim": "it_officer" in roles,
            "can_direct": "it_director" in roles,
        },
    )


@require_held_role("it_officer")
@require_POST
def claim(request, pk):
    """An IT officer claims the whole request (claim.php)."""
    req = get_object_or_404(ITAccessRequest, pk=pk)
    with transaction.atomic():
        req.status = "claimed"
        req.claimed_by = request.user
        req.save(update_fields=["status", "claimed_by"])
        req.systems.filter(status="pending").update(
            status="claimed", claimed_by=request.user, claimed_at=timezone.now()
        )
    messages.success(request, f"You claimed {req.ref_number}.")
    return redirect("it_access:detail", pk=pk)


@require_held_role("it_officer")
@require_POST
def action_system(request, system_id):
    """Mark an individual requested system as actioned."""
    system = get_object_or_404(ITRequestSystem, pk=system_id)
    system.status = "actioned"
    system.actioned_by = request.user
    system.actioned_at = timezone.now()
    system.save(update_fields=["status", "actioned_by", "actioned_at"])

    req = system.request
    if not req.systems.exclude(status="actioned").exists():
        req.status = "awaiting-director"
        req.save(update_fields=["status"])
        messages.info(request, "All systems actioned — request awaiting director sign-off.")
    messages.success(request, f"System '{system.system_id}' actioned.")
    return redirect("it_access:detail", pk=req.pk)


@require_held_role("it_director")
@require_POST
def approve(request, pk):
    """Director signs off (or rejects) provisioning (approve.php)."""
    req = get_object_or_404(ITAccessRequest, pk=pk)
    form = ApprovalForm(request.POST)
    if not form.is_valid():
        messages.error(request, "Invalid approval submission.")
        return redirect("it_access:detail", pk=pk)

    action = form.cleaned_data["action"]
    with transaction.atomic():
        ITRequestApproval.objects.create(
            request=req,
            step_role="director",
            approver=request.user,
            action=action,
            reason=form.cleaned_data.get("reason", ""),
            sig_kind="typed",
            sig_data=form.cleaned_data.get("signature", ""),
        )
        if action == "approved":
            req.status = "provisioned"
            req.provisioned_at = timezone.now()
        else:
            req.status = "rejected"
        req.save(update_fields=["status", "provisioned_at"])

    messages.success(request, f"Request {req.ref_number} {action}.")
    return redirect("it_access:detail", pk=pk)
