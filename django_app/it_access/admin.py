from django.contrib import admin

from .models import ITAccessRequest, ITRequestApproval, ITRequestSystem


class SystemInline(admin.TabularInline):
    model = ITRequestSystem
    extra = 0


class ApprovalInline(admin.TabularInline):
    model = ITRequestApproval
    extra = 0


@admin.register(ITAccessRequest)
class ITAccessRequestAdmin(admin.ModelAdmin):
    list_display = ("ref_number", "employee_name", "department", "status", "submitted_by", "submitted_at")
    list_filter = ("status", "request_type")
    search_fields = ("ref_number", "employee_name", "department")
    inlines = [SystemInline, ApprovalInline]
