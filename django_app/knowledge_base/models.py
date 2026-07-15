"""Knowledge base articles (ports Knowledge_base.php content into a model)."""

from django.db import models
from django.utils import timezone


class Article(models.Model):
    title = models.CharField(max_length=255)
    category = models.CharField(max_length=100, blank=True, default="")
    body = models.TextField()
    is_published = models.BooleanField(default=True)
    created_by = models.CharField(max_length=150, blank=True, default="")
    created_at = models.DateTimeField(default=timezone.now)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        db_table = "knowledge_base"
        ordering = ["category", "title"]

    def __str__(self) -> str:
        return self.title
