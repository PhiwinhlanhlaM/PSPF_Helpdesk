from django import forms

from orgunits.models import Division

from .models import Ticket, TicketFeedback


class TicketForm(forms.ModelForm):
    """Ports the query.php submission form (submit_query2.php validation)."""

    # In the legacy form the department/"To" select carries a division id.
    query_type = forms.ModelChoiceField(
        queryset=Division.objects.all(),
        label="Department (To)",
        empty_label="-- Select --",
    )

    class Meta:
        model = Ticket
        fields = [
            "title",
            "member_type",
            "region",
            "source",
            "query_type",
            "priority",
            "phone_number",
            "description",
            "attachment_path",
        ]
        widgets = {
            "description": forms.Textarea(attrs={"rows": 5}),
        }
        labels = {
            "region": "Branch",
            "member_type": "Member Type",
        }

    attachment = forms.FileField(required=False)

    def clean(self):
        cleaned = super().clean()
        source = cleaned.get("source")
        phone = cleaned.get("phone_number", "")
        if source == "Phone" and not phone:
            self.add_error("phone_number", "Phone number is required when source is Phone.")
        if phone and (not phone.isdigit() or len(phone) > 20):
            self.add_error("phone_number", "Phone number must be digits only (max 20).")
        return cleaned


class TicketStatusForm(forms.Form):
    status = forms.ChoiceField(choices=Ticket.STATUS_CHOICES)
    note = forms.CharField(required=False, widget=forms.Textarea(attrs={"rows": 2}))


class FeedbackForm(forms.ModelForm):
    class Meta:
        model = TicketFeedback
        fields = ["rating", "comment"]
        widgets = {
            "rating": forms.NumberInput(attrs={"min": 1, "max": 5}),
            "comment": forms.Textarea(attrs={"rows": 3}),
        }
