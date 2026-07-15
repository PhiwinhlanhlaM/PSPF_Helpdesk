from django.contrib import admin
from django.contrib.auth.admin import UserAdmin as BaseUserAdmin

from .models import Role, User, UserRole


class UserRoleInline(admin.TabularInline):
    model = UserRole
    extra = 1


@admin.register(User)
class UserAdmin(BaseUserAdmin):
    inlines = [UserRoleInline]
    list_display = ("username", "email", "full_name", "department", "is_active", "is_staff")
    list_filter = ("is_active", "is_staff", "roles")
    search_fields = ("username", "email", "full_name")
    ordering = ("username",)
    fieldsets = (
        (None, {"fields": ("username", "password")}),
        ("Personal", {"fields": ("full_name", "email", "department", "department_fk", "division")}),
        ("Permissions", {"fields": ("is_active", "is_staff", "is_superuser", "groups", "user_permissions")}),
        ("Dates", {"fields": ("last_login", "date_joined", "password_changed_at")}),
    )
    add_fieldsets = (
        (None, {
            "classes": ("wide",),
            "fields": ("username", "email", "password1", "password2"),
        }),
    )


@admin.register(Role)
class RoleAdmin(admin.ModelAdmin):
    list_display = ("name", "description")
    search_fields = ("name",)
