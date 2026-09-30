<x-mail::message>
# Welcome to OralCares, {{ $user->name }}!

Your organization's clinic portal has been successfully provisioned.

To get started and set up your account, please click the button below to create your password and launch the onboarding wizard.

<x-mail::button :url="$setupUrl">
Setup Password
</x-mail::button>

This link is valid for 48 hours. If you need a new link, please contact OralCares support.

Thanks,<br>
The OralCares Team
</x-mail::message>
