# CLI Commands

## BackfillAddressQualityCommand

**File:** `src/Command/BackfillAddressQualityCommand.php`

Backfills Swiss Post address quality statuses for all existing customer addresses. Queries addresses without a certification status and validates them via Swiss Post API.

## CertificationStatsCommand

**File:** `src/Command/CertificationStatsCommand.php`

Shows statistics about Swiss Post address certification: counts of each quality status.

## DiffFixedAddressesCommand

**File:** `src/Command/DiffFixedAddressesCommand.php`

Compares original vs. fixed (after Swiss Post correction) addresses to show what the API changed.

## EnforceAddressSeparationCommand

**File:** `src/Command/EnforceAddressSeparationCommand.php`

Finds customers where billing_address_id == shipping_address_id and creates a separate shipping address. Enforces address isolation for existing data.

## TestSwissPostApiCommand

**File:** `src/Command/TestSwissPostApiCommand.php`

Tests the Swiss Post API connection with configurable test address data.

## ExampleCommand

**File:** `src/Command/ExampleCommand.php`

Example/demo command (plugin development boilerplate).
