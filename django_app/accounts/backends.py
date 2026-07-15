"""Authentication backend allowing login by username *or* email.

Ports authenticateUser() from includes/auth_functions.php, including the
"account disabled" behaviour (inactive users cannot authenticate).
"""

from django.contrib.auth import get_user_model
from django.contrib.auth.backends import ModelBackend
from django.db.models import Q

UserModel = get_user_model()


class UsernameOrEmailBackend(ModelBackend):
    def authenticate(self, request, username=None, password=None, **kwargs):
        if username is None or password is None:
            return None
        try:
            user = UserModel.objects.get(Q(username=username) | Q(email=username))
        except UserModel.DoesNotExist:
            # Mitigate timing attacks by running the hasher once.
            UserModel().set_password(password)
            return None
        except UserModel.MultipleObjectsReturned:
            user = UserModel.objects.filter(
                Q(username=username) | Q(email=username)
            ).order_by("id").first()

        if user.check_password(password) and self.user_can_authenticate(user):
            return user
        return None
