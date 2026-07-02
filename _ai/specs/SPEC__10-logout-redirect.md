# Logout Redirect

## Overview

Configurable redirect target after customer logout. Supports single route, URL path, or per-locale redirects.

## Implementation

**File:** `src/Core/Checkout/Customer/Subscriber/LogoutRedirectSubscriber.php`

Listens on `KernelEvents::RESPONSE` and intercepts redirects from `frontend.account.logout.page`.

### Config

**Key:** `logoutRedirectRoute`
**Default:** `frontend.home.page`
**Type:** textarea

### Single Target Mode

```text
frontend.home.page
```
or
```text
/thank-you
```

### Multi-Line Locale Map

Each line: `locale=target`

```text
de-DE=/Newsletter
fr-FR=/fr/Newsletter
_default=frontend.home.page
```

**Resolution order:** exact locale match → 2-letter fallback (`de` matches `de-DE`) → `_default`

Lines starting with `#` are ignored.

### Behavior

- Checks if the current route is `frontend.account.logout.page`
- Reads config for the sales channel
- Resolves target via locale map
- **Skips** if empty or targeting the login page (`frontend.account.login.page`)
- Creates `RedirectResponse` — either to route name (via `RouterInterface::generate()`) or direct URL
