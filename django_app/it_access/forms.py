from django import forms

from .models import ITAccessRequest


class ITAccessRequestForm(forms.ModelForm):
    """Ports the it_access/submit.php request form.

    Systems are submitted as a comma-separated list (one ITRequestSystem row is
    created per entry) plus the manager's captured signature.
    """

    systems = forms.CharField(
        help_text="Comma-separated system identifiers the employee needs.",
        widget=forms.TextInput(attrs={"placeholder": "e.g. email, crm, finance"}),
    )
    manager_signature = forms.CharField(
        widget=forms.Textarea(attrs={"rows": 2}),
        help_text="Manager's signature (typed name or captured data).",
    )

    class Meta:
        model = ITAccessRequest
        fields = [
            "request_type",
            "employee_name",
            "employee_id",
            "department",
            "division",
            "job_title",
            "start_date",
            "justification",
        ]
        widgets = {
            "start_date": forms.DateInput(attrs={"type": "date"}),
            "justification": forms.Textarea(attrs={"rows": 4}),
        }

    def clean_justification(self):
        j = self.cleaned_data["justification"].strip()
        if len(j) < 10:
            raise forms.ValidationError("Justification must be at least 10 characters.")
        return j

    def clean_systems(self):
        raw = self.cleaned_data["systems"]
        items = [s.strip() for s in raw.split(",") if s.strip()]
        if not items:
            raise forms.ValidationError("At least one system is required.")
        return items


class ApprovalForm(forms.Form):
    ACTION_CHOICES = [("approved", "Approve"), ("rejected", "Reject")]
    action = forms.ChoiceField(choices=ACTION_CHOICES)
    reason = forms.CharField(required=False, widget=forms.Textarea(attrs={"rows": 2}))
    signature = forms.CharField(required=False, widget=forms.Textarea(attrs={"rows": 2}))
