<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\ClinicEmployee;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ApiGatewayService
{
    /**
     * Issue a personal access API token.
     */
    public function generateToken(ClinicEmployee $employee, string $tokenName): string
    {
        $rawToken = 'oc_'.Str::random(40);

        ApiToken::create([
            'clinic_employee_id' => $employee->id,
            'token' => hash('sha256', $rawToken),
            'name' => $tokenName,
            'expires_at' => now()->addDays(30),
        ]);

        return $rawToken;
    }

    /**
     * Authenticate an incoming request token.
     */
    public function authenticateToken(string $rawToken): ?ClinicEmployee
    {
        $hashed = hash('sha256', $rawToken);

        $token = ApiToken::where('token', $hashed)->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        $token->update(['last_used_at' => now()]);

        return $token->employee;
    }

    /**
     * Check rate limits against user, client or IP keys.
     */
    public function checkRateLimit(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, $decaySeconds);

        return true;
    }

    /**
     * Generate dynamic OpenAPI v3.0 JSON-ready schema mapping out versioned resource routes.
     */
    public function generateOpenApiSpec(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'OralCares Clinical HMS API',
                'version' => '1.0.0',
                'description' => 'Exposes patients, encounters, billing, radiology, lab case trials, inventory tracking, queue sessions, and automations.',
            ],
            'servers' => [
                ['url' => '/api/v1', 'description' => 'v1 production endpoint'],
            ],
            'paths' => [
                '/patients' => [
                    'get' => [
                        'summary' => 'List patients',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of patients'],
                        ],
                    ],
                ],
                '/encounters' => [
                    'get' => [
                        'summary' => 'List encounters',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of encounters'],
                        ],
                    ],
                ],
                '/billings' => [
                    'get' => [
                        'summary' => 'List patient billings',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of patient billings'],
                        ],
                    ],
                ],
                '/laboratories' => [
                    'get' => [
                        'summary' => 'List laboratory cases',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of laboratory cases'],
                        ],
                    ],
                ],
                '/xrays' => [
                    'get' => [
                        'summary' => 'List xray orders',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of xray orders'],
                        ],
                    ],
                ],
                '/inventory' => [
                    'get' => [
                        'summary' => 'List inventory items',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of inventory items'],
                        ],
                    ],
                ],
                '/queues' => [
                    'get' => [
                        'summary' => 'List queue configurations',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of queue configurations'],
                        ],
                    ],
                ],
                '/workflows' => [
                    'get' => [
                        'summary' => 'List active workflow runs',
                        'responses' => [
                            '200' => ['description' => 'Standardized paginated list of active workflow runs'],
                        ],
                    ],
                ],
                '/analytics' => [
                    'get' => [
                        'summary' => 'List calculated snapshot KPI data',
                        'responses' => [
                            '200' => ['description' => 'Standardized KPI snapshots'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
