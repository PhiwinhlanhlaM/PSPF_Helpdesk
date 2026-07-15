from django.urls import path

from . import views

app_name = "accounts"

urlpatterns = [
    path("login/", views.login_view, name="login"),
    path("logout/", views.logout_view, name="logout"),
    path("register/", views.register_view, name="register"),
    path("dashboard/", views.dashboard_redirect, name="dashboard"),
    path("switch-role/", views.switch_role_view, name="switch_role"),
    path("profile/", views.profile_view, name="profile"),
]
