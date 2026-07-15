from django.contrib import admin

from .models import Article


@admin.register(Article)
class ArticleAdmin(admin.ModelAdmin):
    list_display = ("title", "category", "is_published", "updated_at")
    list_filter = ("category", "is_published")
    search_fields = ("title", "body")
