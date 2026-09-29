<x-mail::message>
# Reset your password

Hi {{ $user->name }},

Someone asked to reset the password for your clinic portal account. If it was you, use the button below to choose a new one.

<x-mail::button :url="$resetUrl">
Reset password
</x-mail::button>

This link works once and expires in 1 hour. If you didn't ask for a reset, you can ignore this email and your password will stay the same.
</x-mail::message>
