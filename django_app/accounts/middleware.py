"""Request middleware porting enforceActiveUser() and enforcePasswordPolicy().

* ActiveUserMiddleware   — logs out any user whose account was disabled.
* PasswordPolicyMiddleware — once a password is older than PASSWORD_EXPIRY_DAYS,
  the user is confined to the profile (change-password) page until they reset.
"""

from datetime import timedelta

from django.conf import settings
from django.contrib import messages
from django.contrib.auth import logout
from django.shortcuts import redirect
from django.urls import reverse
from django.utils import timezone


class ActiveUserMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        user = getattr(request, "user", None)
        if user is not None and user.is_authenticated and not user.is_active:
            logout(request)
            messages.error(request, "Your account has been disabled.")
            return redirect(f"{reverse('accounts:login')}?error=disabled")
        return self.get_response(request)


class PasswordPolicyMiddleware:
    # Paths a user with an expired password is still allowed to reach.
    def __init__(self, get_response):
        self.get_response = get_response

    def _password_expired(self, user) -> bool:
        if not user.password_changed_at:
            return True
        expiry = user.password_changed_at + timedelta(
            days=settings.PASSWORD_EXPIRY_DAYS
        )
        return timezone.now() > expiry

    def __call__(self, request):
        user = getattr(request, "user", None)
        if user is not None and user.is_authenticated and self._password_expired(user):
            allowed = {
                reverse("accounts:profile"),
                reverse("accounts:logout"),
            }
            if request.path not in allowed and not request.path.startswith(
                settings.STATIC_URL
            ):
                messages.warning(
                    request, "Your password has expired. Please set a new one."
                )
                return redirect(f"{reverse('accounts:profile')}?expired=1")
        return self.get_response(request)
