# Coding standards and conventions

## Language and style
* PHP 8.1+ (developed on 8.3). `declare(strict_types=1);` in every file.
* PSR-12 formatting, one class per file, namespace `App\...` mirrors the folder under `app/`.
* Small methods; early returns; no business logic in controllers or views.
* Comments explain *why*, not what. Keep them rare.

## Layering (who may call whom)
```
Router -> Middleware -> Controller -> Service -> Repository -> Database (PDO)
                              \-> View
```
| Layer | Does | Must not |
|---|---|---|
| Controller | Reads the request, calls `validate()`, calls a service, returns a response | Contain SQL or business rules |
| Service | Business rules (status transitions, bucket logic, imports) | Touch `$_POST`/`$_SESSION` directly or output HTML |
| Repository | SQL only, always prepared statements | Make business decisions |
| View | Display only; every dynamic value goes through `e()` | Query the database |
| Middleware | Cross-cutting checks (CSRF, auth, permission, headers) | Hold feature logic |

## Adding a feature
1. Migration in `database/migrations/NNN_name.sql` (next number; MySQL syntax).
2. Repository -> Service -> Controller -> view(s) under `views/<module>/`.
3. Route in `app/Config/routes.php` **with an access rule** (`->public()`, `->authOnly()` or `->can('key')`). A route without one throws.
4. New permission keys go in `app/Config/permissions.php`, then run `php cli/seed.php`.
5. Navigation entry in `app/Config/navigation.php`.
6. Tests in `tests/run.php`.

## Security rules (non-negotiable)
* **SQL:** prepared statements only. Never concatenate user input into SQL. Sort columns/directions come from a whitelist.
* **Output:** `e()` on every dynamic value. No inline `<script>` or `style=""` (the CSP forbids them).
* **Forms:** every form that changes state is a `POST` containing `<?= csrf_field() ?>`. Never change state on `GET`.
* **Validation:** always server-side via `Validator`; browser validation is only a convenience.
* **Redirects:** only to internal paths (`Response::redirect` enforces this).
* **Logging:** never log passwords, tokens or session ids. `AuditService` redacts keys containing `password`, `token`, `secret`.
* **Money and dates:** money as `DECIMAL`, dates as `DATE`/`DATETIME` in UTC.

## UI rules (every phase, not only the polish phase)
* Bootstrap 5.3 components plus the theme variables in `public/assets/css/app.css`.
* Every screen is checked at 360, 768 and 1280 px before a phase closes.
* Tables use the `table-cards` pattern so they become cards on phones.
* Colour is never the only signal: badges always carry text.
