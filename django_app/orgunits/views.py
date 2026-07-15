"""JSON endpoints ported from departments/list.php and employees/lookup.php."""

from django.contrib.auth import get_user_model
from django.contrib.auth.decorators import login_required
from django.http import JsonResponse

from .models import Department

User = get_user_model()


@login_required
def department_list(request):
    """Departments with their divisions nested (departments/list.php)."""
    data = []
    for dept in Department.objects.prefetch_related("divisions").all():
        data.append({
            "id": dept.id,
            "name": dept.department_name,
            "divisions": [
                {"id": d.id, "name": d.division_name} for d in dept.divisions.all()
            ],
        })
    return JsonResponse({"departments": data})


@login_required
def employee_lookup(request):
    """Resolve a username/email to basic employee details (employees/lookup.php)."""
    ident = (request.GET.get("id") or "").strip()
    if not ident:
        return JsonResponse({"error": "id required"}, status=400)

    user = (
        User.objects.filter(username=ident).first()
        or User.objects.filter(email=ident).first()
    )
    if not user:
        return JsonResponse({"error": "not found"}, status=404)

    return JsonResponse({
        "employeeId": user.username,
        "fullName": user.full_name or user.username,
        "email": user.email,
        "department": user.department or "",
        "jobTitle": "",
    })
