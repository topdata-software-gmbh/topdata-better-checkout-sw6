# Test-Checkliste: topdata-better-checkout-sw6

## 1. Grundkonfiguration (Admin → Einstellungen → Erweiterungen)

- [ ] **Plugin ist installiert und aktiv** – Keine Fehler in der Plugin-Übersicht
- [ ] **Konfigurationsseite aufrufbar** – Alle drei Karten (Account-Typ, Zahlungsrestriktionen, Firmenvalidierung) werden angezeigt
- [ ] **Standardwerte prüfen** – `guestAccountType=user_choice`, `registrationAccountType=always_business`, `companyValidationBilling=core`, `companyValidationShipping=optional`
- [ ] **Werte speicherbar** – Alle Select-Felder und Multi-ID-Selects lassen sich speichern und bleiben nach Neuladen erhalten

---

## 2. 3-Box-Auswahl (Startseite Checkout)

- [ ] **3 Boxen werden angezeigt** – Registrieren / Anmelden / Gast
- [ ] **Box "Registrieren"** führt zu `?checkoutType=register`
- [ ] **Box "Gast"** führt zu `?checkoutType=guest`
- [ ] **Box "Anmelden"** zeigt das Login-Formular direkt an
- [ ] **Nicht eingeloggt** → 3 Boxen sichtbar
- [ ] **Bereits eingeloggt** → Standard-Checkout (keine Boxen)
- [ ] **Mobile Ansicht** – Boxen sind responsive (Stack untereinander)
- [ ] **Übersetzungen** – Box-Titel/-Texte in Deutsch und Englisch korrekt

---

## 3. Registrierungs-Flow (`?checkoutType=register`)

### 3a. Standard-Registrierung
- [ ] **Registrierungsformular wird angezeigt** – Mit Passwortfeld
- [ ] **Passwortfeld sichtbar** – `createCustomerAccount` ist deaktiviert (checked=false)
- [ ] **`checkoutType` bleibt erhalten** – Auch nach Validierungsfehler (verstecktes Input-Feld)

### 3b. Account-Typ: `always_business` (Voreinstellung)
- [ ] **Account-Typ auf "Business" gesetzt** – Keine Auswahl für Kunden sichtbar
- [ ] **Firmenfelder sichtbar** – Firma, Abteilung, Umsatzsteuer-ID werden angezeigt
- [ ] **Kunde wird als Business-Kunde angelegt** – In Admin → Kunde prüfen

### 3c. Account-Typ: `user_choice`
- [ ] **Account-Typ-Auswahl sichtbar** – Privat / Geschäftlich umschaltbar
- [ ] **Firmenfelder erscheinen/verschwinden** – Bei Umschalten

### 3d. Account-Typ: `always_private`
- [ ] **Account-Typ auf "Privat" gesetzt** – Keine Auswahl sichtbar
- [ ] **Firmenfelder ausgeblendet** – Auch nicht per JS einblendbar
- [ ] **Kunde wird als Privatkunde angelegt**

---

## 4. Gast-Flow (`?checkoutType=guest`)

- [ ] **Gastformular wird angezeigt** – Kein Passwortfeld
- [ ] **Passwortfeld versteckt** – `createCustomerAccount` ist checked (Gast)
- [ ] **"Konto anlegen"-Umschalter versteckt**
- [ ] **`checkoutType` bleibt erhalten** – Auch nach Validierungsfehler

### 4a. Account-Typ: `user_choice` (Voreinstellung)
- [ ] **Account-Typ-Auswahl sichtbar** – Privat / Geschäftlich
- [ ] **Gastbestellung mit Privatkonto möglich**
- [ ] **Gastbestellung mit Geschäftskonto möglich** (Firmenfelder sichtbar)

### 4b. Account-Typ: `always_private`
- [ ] **Account-Typ automatisch auf Privat**
- [ ] **Firmenfelder ausgeblendet**
- [ ] **Gast wird als Privatgast angelegt**

### 4c. Account-Typ: `always_business`
- [ ] **Account-Typ automatisch auf Geschäftlich**
- [ ] **Firmenfelder sichtbar und Firma erforderlich**
- [ ] **Gast wird als Geschäftsgast angelegt**

### 4d. E-Mail-Prüfung bei Gastbestellung
- [ ] **E-Mail bereits registriert** – Fehlermeldung "Diese E-Mail-Adresse ist bereits registriert"
- [ ] **E-Mail bereits als Gast genutzt** – Weitere Gastbestellung möglich
- [ ] **Sales-Channel-Bindung** – E-Mail-Prüfung beachtet `isCustomerBoundToSalesChannel`

---

## 5. Adress-Splitting & ERP-Flag

- [ ] **Keine separate Lieferadresse** → Rechnungsadresse wird dupliziert
- [ ] **Zwei separate Address-Datensätze** in der Datenbank (nicht geteilt)
- [ ] **Custom-Feld `is_faktura = 1`** auf Rechnungsadresse
- [ ] **Custom-Feld `is_faktura = 0`** auf Lieferadresse
- [ ] **Separate Lieferadresse angegeben** → Kein Splitting (beide bleiben eigenständig)

---

## 6. Rechnungsadresse-Sperre

- [ ] **"Als Standard-Rechnungsadresse festlegen"-Button versteckt** (Storefront → Adressbuch)
- [ ] **API-Aufruf zum Ändern der Standardrechnungsadresse** → 403 Forbidden
- [ ] **Bearbeiten der Rechnungsadresse möglich** (nicht blockiert)
- [ ] **Lieferadresse als Standard setzbar** (nicht blockiert)

---

## 7. Zahlungsmethoden-Restriktionen (Gast)

### 7a. Privater Gast
- [ ] **Blockierte Zahlarten konfiguriert** (z. B. Rechnung)
- [ ] **Privatgast sieht blockierte Zahlarten nicht** (nur Gäste, nicht registrierte Kunden)
- [ ] **Privatgast sieht erlaubte Zahlarten**

### 7b. Geschäftlicher Gast
- [ ] **Blockierte Zahlarten konfiguriert** (z. B. Kreditkarte)
- [ ] **Geschäftsgast sieht blockierte Zahlarten nicht**
- [ ] **Geschäftsgast sieht erlaubte Zahlarten**

### 7c. Registrierte Kunden (nicht Gast)
- [ ] **Alle Zahlarten sichtbar** – Keine Filterung

---

## 8. Firmenname-Validierung

### 8a. Rechnungsadresse (`companyValidationBilling`)

#### `core` (Standard)
- [ ] **Firmenfeld nicht erforderlich** – Kein `required`-Attribut
- [ ] **Backend-Validierung** – Kein `NotBlank` für Business-Konten

#### `required`
- [ ] **HTML5 `required`** im Template gesetzt
- [ ] **Leeres Firmenfeld** → Validierungsfehler
- [ ] **Nur bei Business-Konten erzwungen** – Privatkonten ohne Pflicht

#### `optional`
- [ ] **Firmenfeld nicht erforderlich**
- [ ] **Kann leer bleiben** – Auch bei Business-Konten

### 8b. Lieferadresse (`companyValidationShipping`)

#### `core`
- [ ] Standard-Shopware-Verhalten

#### `required`
- [ ] **HTML5 `required`** im Template
- [ ] **Leeres Firmenfeld** → Validierungsfehler

#### `optional`
- [ ] **Firmenfeld nicht erforderlich**

### 8c. Unterscheidung Rechnung vs. Lieferung
- [ ] **Unterschiedliche Konfiguration** für Rechnungs- und Lieferadresse
- [ ] `is_faktura` Flag wird korrekt ausgewertet

---

## 9. Übersetzungen (Snippets)

- [ ] **Deutsch (de-DE)** – Alle Texte korrekt
- [ ] **Englisch (en-GB)** – Alle Texte korrekt
- [ ] **Französisch (fr-FR)** – Alle Texte korrekt
- [ ] **Französisch (fr-CH)** – Alle Texte korrekt
- [ ] **Portugiesisch (pt-PT)** – Alle Texte korrekt
- [ ] **Fehlende Snippets** – Keine leeren oder ungültigen Schlüssel

---

## 10. Bestellabschluss (End-to-End)

- [ ] **Registrierung → Bestellung abschließen** – Erfolgreicher Checkout
- [ ] **Gastbestellung → Bestellung abschließen**
- [ ] **Login → Bestellung abschließen**
- [ ] **Bestelldetails korrekt** – Account-Typ, Adressen, Zahlart in Admin-Ansicht prüfen
- [ ] **Bestätigungs-E-Mail** – Keine Fehler

---

## 11. Edge Cases

- [ ] **Checkout ohne JavaScript** – Formular noch nutzbar (hidden inputs funktionieren)
- [ ] **Session-Timeout** – Nach Ablauf der Session wird korrekt zur 3-Box zurückgeleitet
- [ ] **Parallel bestehende Checkout-Registrierung** – Keine Konflikte
- [ ] **Adresse mit Sonderzeichen/Umlauten** – Korrekte Speicherung
- [ ] **Sehr lange Firmennamen** – Kein Layout-Bruch
- [ ] **VAT-ID-Prüfung** – Nur bei Business-Konten relevant
- [ ] **Mehrere Gastbestellungen mit gleicher E-Mail** – Möglich

---

## 12. Admin-API-Beispiel-Controller
- [ ] `GET /api/_action/topdata-better-checkout-sw6/example` → JSON 200 OK

---

## 13. Aufräumen von Testdaten
- [ ] Testkunden löschbar
- [ ] Testbestellungen löschbar / stornierbar
- [ ] Keine verwaisten Adressdatensätze
