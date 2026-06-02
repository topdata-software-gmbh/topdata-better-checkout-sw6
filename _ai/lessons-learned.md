## [2026-06-02] Address Isolation Bug: Theme Template Shadowing Plugin Overrides

### Context
We implemented billing/shipping address isolation in the `TopdataBetterCheckoutSW6` plugin:
- A DAL subscriber (`CustomerAddressIsolationSubscriber`) to prevent setting `default_billing_address_id` equal to `default_shipping_address_id` (returns 403).
- A route decorator (`SetDefaultBillingAddressRouteDecorator`) to block API calls.
- Twig overrides to hide cross-referencing options in dropdowns (e.g., remove "Als Standard-Lieferadresse verwenden" from billing addresses and vice versa).

### Challenge
Despite the plugin overrides being in place, the unwanted options still appeared in the storefront. The user had also made changes to the theme (`topdata-theme-focus-sw6`) that did not help.

### Discovery / Solution
**The root cause was NOT in the plugin — it was in the theme.**

The theme contained a full copy-paste of `address-item.html.twig` (`src/Resources/views/storefront/page/account/addressbook/address-item.html.twig`). Because theme templates take precedence over plugin templates in Shopware, the theme's version completely shadowed the plugin's carefully written overrides. When the user temporarily switched to the **default theme**, the plugin's isolation logic and badge suppression worked perfectly.

**Files deleted from the theme:**
- `address-item.html.twig` — Full copy-paste that shadowed the plugin's override.
- `address-actions.html.twig` — Dead code; this template is **not referenced anywhere** in Shopware 6.7.

**Plugin improvement:**
- Made the dropdown icon configurable via `config.xml` (`addressDropdownIcon` setting, default `paper-pencil`).
- Updated the plugin's `address-item.html.twig` to use `{% sw_icon config('TopdataBetterCheckoutSW6.config.addressDropdownIcon') %}` instead of hardcoding `more-vertical`.
- This preserves the visual customization the theme introduced, without requiring a full template override.

### Key Takeaways
- **Always suspect theme overrides first.** If a plugin's Twig changes don't take effect, check if the active theme (or another plugin with higher priority) is overriding the same template.
- **Full copy-paste Twig overrides are dangerous.** They shadow all upstream changes and create maintenance nightmares. If a theme only needs to change an icon, it should not copy an entire 100-line template.
- **`address-actions.html.twig` is dead in Shopware 6.7.** Do not create or maintain overrides for this template; it is no longer included anywhere in the core.
- **Use `config()` in Twig for visual tweaks.** Instead of a theme template override to change an icon, expose it as a plugin configuration field (`config('PluginName.config.fieldName')`). This is more maintainable and does not break plugin logic.
- **Template hierarchy matters:** Core → Plugin → Theme. The theme wins. If a theme copies a core template, the plugin's `sw_extends` override will be completely ignored.
- **Verify upstream before blaming downstream.** When debugging "why isn't my plugin working?", test with the default theme to isolate whether the plugin is actually broken or being shadowed.
