<?php

namespace App\Contracts;

/**
 * Which database connection holds a clinic's data (ADR-001 §P).
 *
 * Today every clinic lives on the primary connection and the default resolver
 * says so. When measured load justifies splitting tenants across databases,
 * a resolver that maps clinic → shard replaces it here, and code that already
 * asks this contract follows without change. Central tables (users,
 * organisations, clinics, plans, head-office CRM) always stay on the central
 * connection.
 */
interface TenantConnectionResolver
{
    /** The connection name that stores this clinic's records. */
    public function connectionFor(int $clinicId): string;

    /** The connection name for platform-wide (non-tenant) tables. */
    public function centralConnection(): string;
}
