"""Seed a minimal demo dataset so the ported app can be explored end-to-end.

    python manage.py seed_demo

Creates the standard roles, a department/division, and one user per role
(all with password 'password123').
"""

from django.core.management.base import BaseCommand

from accounts.models import Role, User, UserRole
from knowledge_base.models import Article
from orgunits.models import Department, Division

ROLES = [
    ("user", "Standard helpdesk user"),
    ("agent", "Support agent"),
    ("admin", "CRM administrator"),
    ("superadmin", "Full system administrator"),
    ("it_officer", "ICT officer — claims and actions IT access requests"),
    ("it_director", "IT Director — signs off IT access provisioning"),
]

DEMO_USERS = [
    ("alice", "alice@pspf.local", ["user"]),
    ("bob", "bob@pspf.local", ["agent"]),
    ("carol", "carol@pspf.local", ["admin", "user"]),
    ("dave", "dave@pspf.local", ["it_officer"]),
    ("erin", "erin@pspf.local", ["it_director"]),
]


class Command(BaseCommand):
    help = "Seed roles, an org unit, demo users and a KB article."

    def handle(self, *args, **options):
        roles = {}
        for name, desc in ROLES:
            role, _ = Role.objects.get_or_create(name=name, defaults={"description": desc})
            roles[name] = role

        dept, _ = Department.objects.get_or_create(department_name="ICT")
        division, _ = Division.objects.get_or_create(
            division_name="Service Desk", department=dept
        )

        for username, email, role_names in DEMO_USERS:
            user, created = User.objects.get_or_create(
                username=username,
                defaults={
                    "email": email,
                    "full_name": username.capitalize(),
                    "department": "ICT",
                    "department_fk": dept,
                    "division": division,
                },
            )
            if created:
                user.set_password("password123")
                user.save()
            for rn in role_names:
                UserRole.objects.get_or_create(user=user, role=roles[rn])
            self.stdout.write(f"  user {username} ({', '.join(role_names)})")

        Article.objects.get_or_create(
            title="How to log a ticket",
            defaults={
                "category": "Getting started",
                "body": "Go to New Ticket, fill in the form, and submit. "
                "You can track progress from the Track page.",
            },
        )

        self.stdout.write(self.style.SUCCESS("Demo data seeded (password: password123)."))
