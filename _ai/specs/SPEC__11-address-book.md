# Address Book UI Modifications

## Overview

Customizations to the storefront address book pages for the company name change request and billing address lock features.

## Templates

### address-manager-item.html.twig

Custom address card display in the address manager, showing company name and phone number.

### address-item.html.twig

Individual address item display with:
- Read-only company field if a pending company name change exists
- "Change company name" button on billing addresses
- Phone number display (gated by `showPhoneNumberOnAddressCards` config)

### edit.html.twig

Address edit form page with:
- Read-only company field for billing addresses
- Company name change request button

### index.html.twig

Address listing page with pending change request warning banners.

## Config

| Config | Default | Description |
|--------|---------|-------------|
| `addressDropdownIcon` | `paper-pencil` | Icon for the address options dropdown |
| `showPhoneNumberOnAddressCards` | `false` | Show phone number on address cards |
