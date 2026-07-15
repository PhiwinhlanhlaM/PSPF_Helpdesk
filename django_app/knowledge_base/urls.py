from django.urls import path

from . import views

app_name = "knowledge_base"

urlpatterns = [
    path("", views.article_list, name="list"),
    path("<int:pk>/", views.article_detail, name="detail"),
]
