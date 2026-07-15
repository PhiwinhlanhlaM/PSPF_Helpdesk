"""Session-backed active-role helpers and access decorators.

Ports the role machinery from includes/auth_helpers.php and switch_role.php:

* the *active role* is stored on the session (like $_SESSION['active_role']);
* it_officer / it_director are permission roles that can never become the
  active persona;
* getRoleHomePage() maps a role to its landing page.
"""

from functools import wraps

from django.contrib.auth.decorators import login_required
from django.http import HttpResponseForbidden
from django.shortcuts import redirect
from django.urls import reverse

ACTIVE_ROLE_SESSION_KEY = "active_role"

# Roles that are permissions only — never a switchable active persona.
PERMISSION_ONLY_ROLES = {"it_officer", "it_director"}

# getRoleHomePage() — maps an active role to its home url name.
ROLE_HOME = {
    "user": "tickets:user_dashboard",
    "agent": "tickets:agent_dashboard",
    "admin": "tickets:admin_dashboard",
    "superadmin": "tickets:admin_dashboard",
}


def get_active_role(request) -> str:
    """Return the active role, defaulting to the first held role."""
    role = request.session.get(ACTIVE_ROLE_SESSION_KEY)
    if role:
        return role
    names = request.user.role_names() if request.user.is_authenticated else ["user"]
    role = names[0]
    request.session[ACTIVE_ROLE_SESSION_KEY] = role
    return role


def set_active_role(request, role: str) -> bool:
    """Switch the active role if the user actually holds it. Returns success."""
    role = (role or "").strip().lower()
    if role in PERMISSION_ONLY_ROLES:
        return False
    if role in request.user.role_names():
        request.session[ACTIVE_ROLE_SESSION_KEY] = role
        return True
    return False


def role_home_url(role: str) -> str:
    return reverse(ROLE_HOME.get(role, "tickets:user_dashboard"))


def require_roles(*allowed):
    """Decorator: allow only users whose *active* role is in `allowed`."""

    def decorator(view):
        @wraps(view)
        @login_required
        def _wrapped(request, *args, **kwargs):
            if get_active_role(request) not in allowed:
                return HttpResponseForbidden(
                    "<h3>403 - Forbidden</h3><p>Access denied.</p>"
                )
            return view(request, *args, **kwargs)

        return _wrapped

    return decorator


def require_held_role(*allowed):
    """Decorator: allow users who *hold* any of `allowed` (regardless of active).

    Mirrors the IT-access checks that use hasRole() rather than the active role.
    """

    def decorator(view):
        @wraps(view)
        @login_required
        def _wrapped(request, *args, **kwargs):
            held = set(request.user.role_names())
            if held.isdisjoint(allowed):
                return HttpResponseForbidden(
                    "<h3>403 - Forbidden</h3><p>Access denied.</p>"
                )
            return view(request, *args, **kwargs)

        return _wrapped

    return decorator
