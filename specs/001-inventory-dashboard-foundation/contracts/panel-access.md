# Contract: Panel Access

**Surface**: Filament admin panel (`id: admin`, path `/admin`), session-authenticated.

## Behavior

| Actor | `user_type` | Authenticated? | Expected result |
|---|---|---|---|
| System Administrator | `admin` | yes | Access granted (HTTP 200 on `/admin`) |
| Customer | `customer` | yes | Access denied (403 / cannot pass `canAccessPanel`) |
| Employee | `employee` | yes | Access denied |
| Guest | — | no | Redirect to `/admin/login` (302) |

## Interface

`App\Models\User` implements `Filament\Models\Contracts\FilamentUser`:

```
canAccessPanel(Panel $panel): bool
  → for panel id 'admin': true iff user_type === UserType::Admin
  → for any other panel id: false (fail closed — deny by default; a new
    panel must explicitly opt in here, not silently inherit open access)
```

## Verification

- Feature test `PanelAccessTest`: one case per row above.
- Unit test `UserTest`: an unknown panel id is denied regardless of `user_type` (including Admin).
- Must not regress the existing redirect-when-guest case in `DashboardPageTest`.
