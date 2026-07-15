from django.urls import path

from . import views

app_name = "it_access"

urlpatterns = [
    path("", views.index, name="index"),
    path("submit/", views.submit, name="submit"),
    path("<int:pk>/", views.detail, name="detail"),
    path("<int:pk>/claim/", views.claim, name="claim"),
    path("<int:pk>/approve/", views.approve, name="approve"),
    path("system/<int:system_id>/action/", views.action_system, name="action_system"),
]
