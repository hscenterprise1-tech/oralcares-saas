# OralCares Routing Setup

OralCares Enterprise supports a dual-mode routing architecture to accommodate seamless local development while maintaining strict subdomain isolation in production.

This architecture is controlled by the `ROUTING_MODE` environment variable.

## Development Mode (Path-Based)
**Config**: `ROUTING_MODE=path`

**Purpose**: Pure local development using PHP's built-in server (`php artisan serve`). No `hosts` file editing or virtual host configuration required.

**Access URLs**:
- **Company Portal**: `http://127.0.0.1:8000/company/login`
- **Clinic Portal**: `http://127.0.0.1:8000/clinic/login?clinic=my-clinic-slug` (the `clinic` query parameter is optional in dev).

## Production Mode (Domain-Based)
**Config**: `ROUTING_MODE=domain` (Default fallback)

**Purpose**: Staging, Production, or local development using advanced tools like Laravel Herd/Valet which provide automatic DNS resolution.

**Access URLs**:
- **Company Portal**: `http://admin.oralcares.in/login` (or `http://admin.oralcares-saas.test/login` locally)
- **Clinic Portal**: `http://clinicname.oralcares.in/login` (or `http://clinicname.oralcares-saas.test/login` locally)

## How It Works
Both modes share the exact same underlying route definitions (`routes/web.php`). 
- In `domain` mode, routes are bound using `Route::domain()`.
- In `path` mode, routes are bound using `Route::prefix()`.

The `ClinicLoginController` and `PortalMiddlewares` dynamically adapt their domain-extraction and validation logic based on the `config('app.routing_mode')` value, ensuring zero changes to your underlying business logic.
