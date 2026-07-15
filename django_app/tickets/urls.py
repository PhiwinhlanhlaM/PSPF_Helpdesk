from django.urls import path

from . import views

app_name = "tickets"

urlpatterns = [
    # User
    path("dashboard/", views.user_dashboard, name="user_dashboard"),
    path("new/", views.query_view, name="query"),
    path("success/<int:ticket_id>/", views.ticket_success, name="success"),
    path("track/", views.ticket_track, name="track"),
    path("<int:ticket_id>/", views.ticket_detail, name="detail"),
    path("<int:ticket_id>/feedback/", views.submit_feedback, name="feedback"),
    # Agent
    path("agent/dashboard/", views.agent_dashboard, name="agent_dashboard"),
    # Admin
    path("admin/dashboard/", views.admin_dashboard, name="admin_dashboard"),
    # Actions / JSON
    path("<int:ticket_id>/status/", views.change_status_view, name="change_status"),
    path("api/status-summary/", views.ticket_status_summary, name="status_summary"),
]
