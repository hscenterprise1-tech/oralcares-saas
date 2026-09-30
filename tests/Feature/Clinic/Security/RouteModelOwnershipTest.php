<?php

use App\Models\Branch;
use App\Models\ClinicQueueItem;
use App\Models\ClinicRoom;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/*
 * Guards against the most likely future IDOR: a clinic route that binds a
 * model from an id in the URL, where the model has no clinic scope of its own.
 * Every model bound on a /clinic route must either use `ClinicScoped` (the
 * binding itself then 404s across clinics) or appear here with the ownership
 * check that protects it. Adding a new unscoped model to a route fails this
 * test until one of the two is true. See docs/security/child-model-ownership.md.
 */
const OWNERSHIP_CHECKED_ELSEWHERE = [
    ClinicQueueItem::class => 'ReceptionController::assertOwned (queue → clinic + branch)',
    ClinicRoom::class => 'RoomController (room.branch_id ∈ clinic branches)',
    Branch::class => 'BranchController (branch.clinic_id), OrganizationScoped',
];

it('binds only clinic-scoped models, or models with a documented ownership check, on clinic routes', function () {
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'clinic/') || ! is_string($route->getAction('uses')) || ! str_contains($route->getAction('uses'), '@')) {
            continue;
        }

        [$controller, $method] = explode('@', $route->getAction('uses'));
        $parameters = (new ReflectionMethod($controller, $method))->getParameters();

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (! is_subclass_of($class, Model::class) || ! in_array($parameter->getName(), $route->parameterNames(), true)) {
                continue;
            }

            $scoped = in_array(ClinicScoped::class, class_uses_recursive($class), true);

            if (! $scoped && ! array_key_exists($class, OWNERSHIP_CHECKED_ELSEWHERE)) {
                $violations[] = "{$route->uri()} binds ".class_basename($class).' with no clinic scope and no documented ownership check';
            }
        }
    }

    expect($violations)->toBe([]);
});
