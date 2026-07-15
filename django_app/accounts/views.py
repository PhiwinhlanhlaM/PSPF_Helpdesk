"""Auth views — porting the signin/, switch_role.php and settings/profile.php flows."""

from django.contrib import messages
from django.contrib.auth import authenticate, login, logout, update_session_auth_hash
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render
from django.views.decorators.http import require_POST

from .forms import ChangePasswordForm, LoginForm, RegistrationForm
from .models import Role, UserRole
from .roles import get_active_role, role_home_url, set_active_role


def login_view(request):
    if request.user.is_authenticated:
        return redirect("accounts:dashboard")

    form = LoginForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        user = authenticate(
            request,
            username=form.cleaned_data["username"],
            password=form.cleaned_data["password"],
        )
        if user is not None:
            login(request, user)
            # Seed the active role from the user's first held role.
            request.session["active_role"] = user.role_names()[0]
            return redirect("accounts:dashboard")
        messages.error(request, "Invalid username or password")

    if request.GET.get("error") == "disabled":
        messages.error(request, "Your account has been disabled.")
    return render(request, "accounts/login.html", {"form": form})


@require_POST
@login_required
def logout_view(request):
    logout(request)
    return redirect("accounts:login")


def register_view(request):
    form = RegistrationForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        user = form.save()
        # Every new account gets the baseline "user" role.
        role, _ = Role.objects.get_or_create(
            name="user", defaults={"description": "Standard helpdesk user"}
        )
        UserRole.objects.get_or_create(user=user, role=role)
        messages.success(request, "Account created. You can now sign in.")
        return redirect("accounts:login")
    return render(request, "accounts/register.html", {"form": form})


@login_required
def dashboard_redirect(request):
    """Central landing page — routes to the active role's home (getRoleHomePage)."""
    return redirect(role_home_url(get_active_role(request)))


@require_POST
@login_required
def switch_role_view(request):
    requested = request.POST.get("role", "")
    if set_active_role(request, requested):
        return redirect(role_home_url(requested.strip().lower()))
    # Not held / not switchable — go back to the current home.
    return redirect(role_home_url(get_active_role(request)))


@login_required
def profile_view(request):
    form = ChangePasswordForm(request.user, request.POST or None)
    if request.method == "POST" and form.is_valid():
        request.user.set_password(form.cleaned_data["new_password1"])
        request.user.save()
        # Keep the user logged in after the password change.
        update_session_auth_hash(request, request.user)
        messages.success(request, "Password updated successfully.")
        return redirect("accounts:dashboard")

    expired = request.GET.get("expired") == "1"
    return render(
        request, "accounts/profile.html", {"form": form, "expired": expired}
    )
