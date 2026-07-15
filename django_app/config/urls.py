from django.conf import settings
from django.conf.urls.static import static
from django.contrib import admin
from django.shortcuts import redirect
from django.urls import include, path

urlpatterns = [
    path("", lambda request: redirect("accounts:dashboard")),
    path("admin/", admin.site.urls),
    path("accounts/", include("accounts.urls")),
    path("tickets/", include("tickets.urls")),
    path("it-access/", include("it_access.urls")),
    path("org/", include("orgunits.urls")),
    path("knowledge-base/", include("knowledge_base.urls")),
]

if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
