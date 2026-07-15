from django.contrib import admin

from .models import Ticket, TicketFeedback, TicketStatusLog


class TicketStatusLogInline(admin.TabularInline):
    model = TicketStatusLog
    extra = 0
    readonly_fields = ("old_status", "new_status", "changed_by", "changed_at")


@admin.register(Ticket)
class TicketAdmin(admin.ModelAdmin):
    list_display = ("id", "title", "status", "priority", "created_by", "division", "query_date")
    list_filter = ("status", "priority", "division")
    search_fields = ("title", "description", "created_by")
    inlines = [TicketStatusLogInline]
    date_hierarchy = "query_date"


@admin.register(TicketStatusLog)
class TicketStatusLogAdmin(admin.ModelAdmin):
    list_display = ("ticket", "old_status", "new_status", "changed_by", "changed_at")
    list_filter = ("new_status",)


@admin.register(TicketFeedback)
class TicketFeedbackAdmin(admin.ModelAdmin):
    list_display = ("ticket", "rating", "submitted_by", "submitted_at")
