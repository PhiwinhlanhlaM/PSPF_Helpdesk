"""
Organisational units — departments and their divisions.

Ports the `departments` and `divisions` tables. In the legacy schema tickets
are routed to a *division* (query_type = division id), and users belong to a
department/division.
"""

from django.db import models


class Department(models.Model):
    department_name = models.CharField(max_length=255)

    class Meta:
        db_table = "departments"
        ordering = ["department_name"]

    def __str__(self) -> str:
        return self.department_name


class Division(models.Model):
    division_name = models.CharField(max_length=255)
    department = models.ForeignKey(
        Department,
        on_delete=models.CASCADE,
        related_name="divisions",
        db_column="department_id",
        null=True,
        blank=True,
    )

    class Meta:
        db_table = "divisions"
        ordering = ["division_name"]

    def __str__(self) -> str:
        return self.division_name
