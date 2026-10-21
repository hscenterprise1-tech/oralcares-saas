<x-mail::message>
# Hi {{ $ownerName }},

**{{ $clinicName }}** is ready for you. Accept the invitation to set your password, then set up your clinic: hours, services, appointments and your team.

<x-mail::button :url="$acceptUrl">
Accept & set up your clinic
</x-mail::button>

The link is valid for {{ $validDays }} days. You can stop at any point and pick up where you left off.

Thanks,<br>
The OralCares Team
</x-mail::message>
