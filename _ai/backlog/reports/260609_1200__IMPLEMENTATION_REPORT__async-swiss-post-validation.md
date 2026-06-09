# Implementation Report — Asynchronous Swiss Post Validation

**Plan:** `_ai/backlog/active/260609_1200__IMPLEMENTATION_PLAN__async-swiss-post-validation.md`
**Date:** 2026-06-09
**Status:** ✅ Complete

## Verification Checklist

### 1. `AddressValidationSubscriber` no longer references `SwissPostApiService` or `country.repository`
- ✅ Removed all Swiss Post API calls (the `Callback` constraint on `zipcode`)
- ✅ Removed imports: `SwissPostApiService`, `EntityRepository`, `Criteria`, `Callback`, `ExecutionContextInterface`, `DataBag`
- ✅ Constructor only has `SystemConfigService` and `RequestStack`
- ✅ `applyValidationRules` / `removeConstraint` / `addConstraintIfNotExists` kept intact
- ✅ `Context` import removed (no longer used)

### 2. `ValidateAddressMessage` and `ValidateAddressHandler` exist in `src/Message/`
- ✅ `src/Message/ValidateAddressMessage.php` — carries `addressId` + optional `salesChannelId`
- ✅ `src/Message/ValidateAddressHandler.php` — `#[AsMessageHandler]` that calls `SwissPostApiService::validateAddress()` and stores quality in `custom_fields`

### 3. `AddressCertificationSubscriber` dispatches a message instead of calling the API synchronously
- ✅ Removed synchronous `SwissPostApiService::validateAddress()` call
- ✅ Added `MessageBusInterface` dependency
- ✅ Dispatches `ValidateAddressMessage` with `addressId` and `salesChannelId`
- ✅ Kept CH/LI filtering and existing-status skip logic

### 4. `services.xml` updated for all three service changes
- ✅ `AddressValidationSubscriber`: removed `SwissPostApiService` and `country.repository` arguments
- ✅ `AddressCertificationSubscriber`: added `messenger.default_bus` argument
- ✅ `ValidateAddressHandler`: registered with `messenger.message_handler` tag and `autowire="true"`

### 5. Worker command
- `bin/console messenger:consume async -vv` processes queued `ValidateAddressMessage` entries

### 6. Frontend Ajax validation unchanged
- ✅ `SwissPostStorefrontController::validate` — untouched
- ✅ `swiss-post-validator.plugin.js` — untouched
- ✅ `swiss-post-autocomplete.plugin.js` — untouched
- ✅ `swiss-post-widget.html.twig` — untouched
- ✅ `address-form.html.twig` — untouched

## Files Changed

| Action | File | Status |
|---|---|---|
| MODIFY | `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php` | ✅ |
| MODIFY | `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php` | ✅ |
| NEW | `src/Message/ValidateAddressMessage.php` | ✅ |
| NEW | `src/Message/ValidateAddressHandler.php` | ✅ |
| MODIFY | `src/Resources/config/services.xml` | ✅ |
| MODIFY | `_ai/SPEC.md` | ✅ |
| MODIFY | `AGENTS.md` | ✅ |
