from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, render

from .models import Article


@login_required
def article_list(request):
    q = (request.GET.get("q") or "").strip()
    articles = Article.objects.filter(is_published=True)
    if q:
        articles = articles.filter(title__icontains=q) | articles.filter(body__icontains=q)
    return render(
        request, "knowledge_base/list.html", {"articles": articles, "q": q}
    )


@login_required
def article_detail(request, pk):
    article = get_object_or_404(Article, pk=pk, is_published=True)
    return render(request, "knowledge_base/detail.html", {"article": article})
