"""Expose role info to every template (like the PHP header includes did)."""

from .roles import get_active_role


def roles(request):
    if not request.user.is_authenticated:
        return {"active_role": None, "held_roles": []}
    held = request.user.role_names()
    return {
        "active_role": get_active_role(request),
        "held_roles": held,
        # Only show the switcher when there is more than one *persona* role.
        "switchable_roles": [
            r for r in held if r not in {"it_officer", "it_director"}
        ],
    }
