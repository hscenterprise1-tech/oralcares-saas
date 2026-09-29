<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

class ContactPolicy
{
    use ChecksCrmPermissions;

    /**
     * Contacts carry named individuals with email and phone, so visibility is
     * gated on an explicit CRM permission rather than on being a CompanyUser.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-contacts', 'view-leads', 'view-crm');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-contacts', 'view-leads', 'view-crm')
            && $this->sharesTenantWith($user, $contact);
    }

    public function create(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'create leads', 'view-contacts', 'view-crm');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->hasAnyCrmPermission($user, 'edit own leads', 'view-contacts', 'view-crm')
            && $this->sharesTenantWith($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->hasAnyCrmPermission($user, 'edit own leads', 'view-contacts', 'view-crm')
            && $this->sharesTenantWith($user, $contact);
    }

    /**
     * Whether this contact belongs to the user's tenant.
     *
     * Every method above took a `Contact` and never looked at it: authorization
     * asked only whether the user holds a CRM permission, which every CRM role
     * does. `PUT` and `DELETE /company/crm/contacts/{id}` were therefore open
     * across tenants — the index is scoped by CrmScope, but an id is a number
     * and the write endpoints never checked one.
     *
     * The rule is CrmScope's own: a contact belongs to the tenant directly, or
     * through the lead it was captured against, because a lead-attached contact
     * may carry no organisation of its own until the deal is won.
     */
    protected function sharesTenantWith(User $user, Contact $contact): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        $orgId = $user->resolveOrganizationId();

        if (! $orgId) {
            return false;
        }

        if ((int) $contact->organization_id === (int) $orgId) {
            return true;
        }

        return $contact->lead !== null
            && (int) $contact->lead->organization_id === (int) $orgId;
    }

    public function restore(User $user, Contact $contact): bool
    {
        return $this->delete($user, $contact);
    }

    public function forceDelete(User $user, Contact $contact): bool
    {
        return $this->delete($user, $contact);
    }
}
