<p>Hello,</p>
<p>You have been invited to join OralCares as a company employee.</p>
<p>Please click the link below to accept the invitation and set up your account password:</p>
<p><a href="{{ $invitationUrl }}">{{ $invitationUrl }}</a></p>
<p>This link will expire on {{ $invitation->expires_at->toDayDateTimeString() }}.</p>
