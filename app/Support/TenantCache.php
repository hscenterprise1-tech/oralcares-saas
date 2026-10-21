<?php

namespace App\Support;

use App\Models\Clinic;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Cache keys for a clinic's data (ADR-001 §K).
 *
 * Every key is `t:{clinic_id}:v{version}:{domain}:{key}`, built only here, so
 * no cached value can be read under another clinic's id and no key can be
 * built without a clinic. `flush()` bumps the clinic's version, which retires
 * all of its keys at once without tags or a scan; the old entries expire on
 * their TTL. Platform-wide values use `global()` (`g:{domain}:{key}`).
 */
class TenantCache
{
    /**
     * The versioned key for one clinic's cached value.
     */
    public static function key(Clinic|int $clinic, string $domain, string $key): string
    {
        $clinicId = static::clinicId($clinic);

        return "t:{$clinicId}:v".static::version($clinicId).":{$domain}:{$key}";
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(Clinic|int $clinic, string $domain, string $key, DateTimeInterface|DateInterval|int $ttl, Closure $callback): mixed
    {
        return Cache::remember(static::key($clinic, $domain, $key), $ttl, $callback);
    }

    public static function forget(Clinic|int $clinic, string $domain, string $key): void
    {
        Cache::forget(static::key($clinic, $domain, $key));
    }

    /**
     * Retire every cached value of this clinic.
     */
    public static function flush(Clinic|int $clinic): void
    {
        $versionKey = static::versionKey(static::clinicId($clinic));

        Cache::add($versionKey, 1);
        Cache::increment($versionKey);
    }

    /**
     * A platform-wide key (reference data shared by every tenant).
     */
    public static function global(string $domain, string $key): string
    {
        return "g:{$domain}:{$key}";
    }

    private static function version(int $clinicId): int
    {
        return (int) Cache::rememberForever(static::versionKey($clinicId), fn () => 1);
    }

    private static function versionKey(int $clinicId): string
    {
        return "t:{$clinicId}:v";
    }

    private static function clinicId(Clinic|int $clinic): int
    {
        $clinicId = $clinic instanceof Clinic ? (int) $clinic->getKey() : $clinic;

        if ($clinicId <= 0) {
            throw new InvalidArgumentException('A tenant cache key needs a clinic.');
        }

        return $clinicId;
    }
}
