# Multilingual Snippets

## Languages

| Language | File | ISO |
|----------|------|-----|
| English (UK) | `storefront.en-GB.json` | `en-GB` |
| German (Germany) | `storefront.de-DE.json` | `de-DE` |
| French (France) | `storefront.fr-FR.json` | `fr-FR` |
| French (Switzerland) | `storefront.fr-CH.json` | `fr-CH` |
| Portuguese (Portugal) | `storefront.pt-PT.json` | `pt-PT` |

## Snippet Namespaces

| Prefix | Area |
|--------|------|
| `better-checkout.*` | General plugin UI labels, error messages |
| `checkout.confirmChangeBillingAddress` | Billing address edit on confirm page |
| `better-checkout.register.*` | Registration-related messages |
| `better-checkout.companyChange.*` | Company name change request UI |
| `better-checkout.swissPost*` | Swiss Post validation messages |
| `TopdataBetterCheckoutSW6.validation.*` | ZIP/country validation error messages |

## SnippetFile Classes

Each language has a `SnippetFile_{lang}.php` implementing `SnippetFileInterface`:
- `getName()`: `storefront.{iso}`
- `getPath()`: points to corresponding JSON file
- `getIso()`: language ISO code
- `getAuthor()`: TopData Software GmbH
- `isBase()`: `false` (not base snippets)
