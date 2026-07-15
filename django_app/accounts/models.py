"""
Accounts — users, roles and the user↔role assignment.

Ports the legacy `users`, `roles` and `user_roles` tables and the multi-role
model used throughout the PHP app (a user *holds* several roles and switches an
*active* role for the session; see auth_helpers.php / switch_role.php).
"""

from django.contrib.auth.models import AbstractBaseUser, BaseUserManager, PermissionsMixin
from django.db import models
from django.utils import timezone


class Role(models.Model):
    """A permission/persona role: user, agent, admin, superadmin, it_officer, it_director."""

    name = models.CharField(max_length=100, unique=True)
    description = models.CharField(max_length=255, blank=True, default="")

    class Meta:
        db_table = "roles"
        ordering = ["name"]

    def __str__(self) -> str:
        return self.name


class UserManager(BaseUserManager):
    def create_user(self, username, email=None, password=None, **extra):
        if not username:
            raise ValueError("Username is required")
        email = self.normalize_email(email) if email else ""
        user = self.model(username=username, email=email, **extra)
        user.set_password(password)
        user.save(using=self._db)
        return user

    def create_superuser(self, username, email=None, password=None, **extra):
        extra.setdefault("is_staff", True)
        extra.setdefault("is_superuser", True)
        extra.setdefault("is_active", True)
        user = self.create_user(username, email, password, **extra)
        # Also grant the app-level superadmin role.
        role, _ = Role.objects.get_or_create(
            name="superadmin",
            defaults={"description": "Full system administrator"},
        )
        UserRole.objects.get_or_create(user=user, role=role)
        return user


class User(AbstractBaseUser, PermissionsMixin):
    """Custom user mapped to the legacy `users` table.

    `password_changed_at` mirrors the legacy `Updated_at` column used by the
    90-day password expiry policy.
    """

    username = models.CharField(max_length=150, unique=True)
    full_name = models.CharField(max_length=150, blank=True, default="")
    email = models.EmailField(max_length=255, blank=True, default="")
    # Legacy free-text department label (kept alongside the FK for parity).
    department = models.CharField(max_length=255, blank=True, default="")
    department_fk = models.ForeignKey(
        "orgunits.Department",
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        db_column="department_id",
        related_name="users",
    )
    division = models.ForeignKey(
        "orgunits.Division",
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        db_column="division_id",
        related_name="users",
    )
    is_active = models.BooleanField(default=True)
    is_staff = models.BooleanField(default=False)
    date_joined = models.DateTimeField(default=timezone.now)
    # Timestamp of the last password change — drives password expiry.
    password_changed_at = models.DateTimeField(
        db_column="Updated_at", default=timezone.now
    )

    roles = models.ManyToManyField(Role, through="UserRole", related_name="users")

    objects = UserManager()

    USERNAME_FIELD = "username"
    REQUIRED_FIELDS = ["email"]

    class Meta:
        db_table = "users"

    def __str__(self) -> str:
        return self.username

    # -- role helpers (port of auth_helpers.php) ---------------------------
    def role_names(self):
        """Lower-cased role names held by this user (fallback ['user'])."""
        names = [r.name.lower() for r in self.roles.all()]
        return names or ["user"]

    def has_app_role(self, role: str) -> bool:
        return role.lower() in self.role_names()

    def set_password(self, raw_password):
        super().set_password(raw_password)
        # Keep the expiry clock in sync whenever the password changes.
        self.password_changed_at = timezone.now()


class UserRole(models.Model):
    """Join table `user_roles` (user_id, role_id)."""

    user = models.ForeignKey(User, on_delete=models.CASCADE, db_column="user_id")
    role = models.ForeignKey(Role, on_delete=models.CASCADE, db_column="role_id")

    class Meta:
        db_table = "user_roles"
        unique_together = ("user", "role")

    def __str__(self) -> str:
        return f"{self.user.username}:{self.role.name}"
