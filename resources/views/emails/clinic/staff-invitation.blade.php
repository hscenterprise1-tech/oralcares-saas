<x-mail::message>
# Hi {{ $employee->name }},

{{ $inviterName }} has invited you to join **{{ $clinicName }}**.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

The link is valid for {{ \App\Services\Staff\StaffInvitationService::VALID_DAYS }} days. If it has expired, ask {{ $inviterName }} to send a new one.

Thanks,<br>
{{ $clinicName }}
</x-mail::message>
