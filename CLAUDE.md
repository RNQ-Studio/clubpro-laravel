# CLAUDE.md — AI Agent Guidelines & Context

This file serves as a quick entrypoint and guide for AI Coding Agents (such as Claude, Gemini, GPT, Cursor, etc.) working on the **Laravel Starter** project.

---

## 🚀 Tech Stack

- **Framework**: Laravel 13.x (PHP 8.3+)
- **Database**: PostgreSQL 16/17
- **Admin Panel / Back-office**: Filament v5
- **Auth (API)**: Laravel Passport v13 (OAuth2, Password Grant)
- **Auth (Back-office)**: Session-based Auth via Filament
- **RBAC**: Spatie Laravel-Permission v7
- **Quality Gates**: PHPUnit v12, Laravel Pint (PSR-12), Larastan/PHPStan v3

---

## 🌐 Production Backend

- **Satu-satunya domain backend: `https://anaklangit.manahpro.web.id`** (app mobile memakai `https://anaklangit.manahpro.web.id/api/`).
- Jangan menambahkan, mendokumentasikan, atau mengarahkan apa pun ke domain lain. `localhost`/`127.0.0.1` hanya untuk server dev lokal.

---

## 🛠️ Commands Reference

### Environment Control
- **Start Sail (Docker)**: `./vendor/bin/sail up -d`
- **Stop Sail (Docker)**: `./vendor/bin/sail down`
- **Artisan (Sail)**: `./vendor/bin/sail artisan [command]`
- **Composer (Sail)**: `./vendor/bin/sail composer [command]`

### Quality Gates & Verification
- **Run Tests**: `composer test` or `php artisan test`
- **Run Linter / Code Formatting**: `composer lint` or `vendor/bin/pint`
- **Run Static Analysis (Larastan)**: `composer analyse` or `vendor/bin/phpstan analyse --memory-limit=1G`

### Setup & Migrations
- **Project Setup**: `composer run setup` (runs install, env copy, key generation, npm build)
- **Run Migrations & Seed**: `php artisan migrate --seed`
- **Generate Passport Keys & Client**:
  ```bash
  php artisan passport:keys
  php artisan passport:client --password
  ```

---

## 📐 Coding Conventions & Architecture

### 1. Architectural Layers & Separation of Concerns
- **No Repository Pattern**: Access Eloquent models directly in Services. Do not write repository interfaces or classes.
- **Service Layer**: Keep controllers thin. Put all business logic in service classes under `app/Services/` (e.g., `OtpService`, `PushNotificationService`, `AuthService`).
- **Central Exception Handling**: Avoid manual try-catch loops in Controllers for expected errors. All exceptions are handled/mapped globally in `bootstrap/app.php`.

### 2. API Guidelines
- **Unified Response Envelope**: Every API response must use the `App\Support\ApiResponse` wrapper.
  - Success: `ApiResponse::success($data, $message, $statusCode)`
  - Error: `ApiResponse::error($message, $statusCode, $errors, $meta)`
- **V1 Namespace**: API Controllers and routes are versioned and located in `app/Http/Controllers/Api/V1/` and defined in `routes/api.php`.

### 3. Back-office (Filament) Guidelines
- **Modular Filament Resources**: Do not let Resource classes grow too large. Organize Filament resources under a modular directory structure:
  - `app/Filament/Resources/[ResourceName]Resource.php` (Main definition)
  - `app/Filament/Resources/[ResourceName]Resource/Schemas/` (Form schemas and inputs)
  - `app/Filament/Resources/[ResourceName]Resource/Tables/` (Table columns, filters, actions)
  - `app/Filament/Resources/[ResourceName]Resource/Pages/` (Resource pages like List, Create, Edit)

### 4. Database & Models
- **Strict Typing & Docblocks**: Every Eloquent model must have `@property` docblocks at the top declaring columns and relations with types (e.g. see `User.php` or `UserDevice.php`). This helps autocomplete.
- **Naming Style**:
  - Classes/Files: `PascalCase`
  - Methods/Properties: `camelCase`
  - DB Columns/Variables: `snake_case` (consistent with PSR-12 and Laravel Pint)
  - Strictly enforce code styling using Laravel Pint before committing.

### 5. Blueprints
- **CRUD Blueprint**: The `Category` model, API Controller, Form Request, API Resource, Policy, and modular Filament Resource serve as the absolute blueprint for replication when adding new master data modules. Check `docs/DATA_MASTER_PATTERN.md` for specific instructions.

---

## 🔒 Security
- Enforce HTTPS in production (`AppServiceProvider.php`).
- Central authorization using Spatie policies mapped via Laravel naming conventions.
- Passport proxies should use debug-aware secure error masking in non-debug environments (refer to `AuthService.php`).
