"""
IT Access request module.

Direct port of it_access/migrations/000_it_access_base.sql:
  * it_access_requests   — one row per request (REQ-YYYY-NNNN)
  * it_request_systems   — the systems requested, each independently claimable
  * it_request_approvals — approval/action trail incl. captured signatures
"""

from django.conf import settings
from django.db import models
from django.utils import timezone


class ITAccessRequest(models.Model):
    REQUEST_TYPES = [("new", "New"), ("change", "Change")]
    STATUS_CHOICES = [
        ("new", "New"),
        ("claimed", "Claimed"),
        ("awaiting-director", "Awaiting Director"),
        ("provisioned", "Provisioned"),
        ("rejected", "Rejected"),
    ]

    ref_number = models.CharField(max_length=20, unique=True)
    request_type = models.CharField(max_length=10, choices=REQUEST_TYPES, default="new")
    employee_name = models.CharField(max_length=255)
    employee_id = models.CharField(max_length=100, null=True, blank=True)
    department = models.CharField(max_length=255)
    division = models.CharField(max_length=255, null=True, blank=True)
    job_title = models.CharField(max_length=255)
    start_date = models.DateField()
    justification = models.TextField()
    submitted_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.PROTECT,
        db_column="submitted_by",
        related_name="it_requests_submitted",
    )
    submitted_at = models.DateTimeField(default=timezone.now)
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default="new")
    claimed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        db_column="claimed_by",
        related_name="it_requests_claimed",
    )
    provisioned_at = models.DateTimeField(null=True, blank=True)
    pdf_filename = models.CharField(max_length=255, null=True, blank=True)
    sharepoint_id = models.CharField(max_length=255, null=True, blank=True)

    class Meta:
        db_table = "it_access_requests"
        ordering = ["-submitted_at"]

    def __str__(self) -> str:
        return self.ref_number


class ITRequestSystem(models.Model):
    STATUS_CHOICES = [
        ("pending", "Pending"),
        ("claimed", "Claimed"),
        ("actioned", "Actioned"),
    ]

    request = models.ForeignKey(
        ITAccessRequest,
        on_delete=models.CASCADE,
        db_column="request_id",
        related_name="systems",
    )
    system_id = models.CharField(max_length=100)
    role = models.CharField(max_length=255, null=True, blank=True)
    sub_values = models.TextField(null=True, blank=True)
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default="pending")
    claimed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        db_column="claimed_by",
        related_name="it_systems_claimed",
    )
    claimed_at = models.DateTimeField(null=True, blank=True)
    actioned_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        db_column="actioned_by",
        related_name="it_systems_actioned",
    )
    actioned_at = models.DateTimeField(null=True, blank=True)

    class Meta:
        db_table = "it_request_systems"

    def __str__(self) -> str:
        return f"{self.request.ref_number}:{self.system_id}"


class ITRequestApproval(models.Model):
    STEP_ROLES = [
        ("manager", "Manager"),
        ("officer-1", "Officer"),
        ("director", "Director"),
    ]
    ACTIONS = [("approved", "Approved"), ("rejected", "Rejected")]

    request = models.ForeignKey(
        ITAccessRequest,
        on_delete=models.CASCADE,
        db_column="request_id",
        related_name="approvals",
    )
    step_role = models.CharField(max_length=20, choices=STEP_ROLES)
    approver = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.PROTECT,
        db_column="approver_id",
        related_name="it_approvals",
    )
    action = models.CharField(max_length=20, choices=ACTIONS)
    acted_at = models.DateTimeField(default=timezone.now)
    reason = models.TextField(null=True, blank=True)
    sig_kind = models.CharField(max_length=20, null=True, blank=True)
    sig_data = models.TextField(null=True, blank=True)
    actioned_systems = models.TextField(null=True, blank=True)

    class Meta:
        db_table = "it_request_approvals"
        ordering = ["acted_at"]

    def __str__(self) -> str:
        return f"{self.request.ref_number}:{self.step_role}:{self.action}"
