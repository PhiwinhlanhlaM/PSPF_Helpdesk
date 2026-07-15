from django.urls import path

from . import views

app_name = "orgunits"

urlpatterns = [
    path("departments/", views.department_list, name="department_list"),
    path("employees/lookup/", views.employee_lookup, name="employee_lookup"),
]
