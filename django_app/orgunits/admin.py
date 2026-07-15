from django.contrib import admin

from .models import Department, Division


class DivisionInline(admin.TabularInline):
    model = Division
    extra = 1


@admin.register(Department)
class DepartmentAdmin(admin.ModelAdmin):
    list_display = ("id", "department_name")
    inlines = [DivisionInline]
    search_fields = ("department_name",)


@admin.register(Division)
class DivisionAdmin(admin.ModelAdmin):
    list_display = ("id", "division_name", "department")
    list_filter = ("department",)
    search_fields = ("division_name",)
