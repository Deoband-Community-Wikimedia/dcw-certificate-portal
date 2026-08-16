# DCW Certificate Portal — Translation Contributor Guide

> **Core Engine Architected by Zaid**
> Zero-database, JSON-driven translation engine built for the Deoband Community Wikimedia Certificate Portal.

Welcome! This guide explains how to add a new language to the DCW Certificate Portal.

---

## 📁 Directory Structure

```
i18n/
├── en.json      ← Master English strings (source of truth — do not edit unless adding new keys)
├── es.json      ← Spanish translations
├── qqq.json     ← Translatewiki.net documentation for every message key
└── README.md    ← This file
```

---

## 🌐 How to Add a New Language

### Step 1: Copy the English bundle

```bash
cp i18n/en.json i18n/fr.json   # Example: French
```

### Step 2: Translate every value

Open your new file (e.g. `i18n/fr.json`) and replace **only the values** (right side of each key).  
**Do NOT change the keys** (left side). Do NOT translate `@metadata`.

```json
{
    "@metadata": {
        "authors": ["YourWikimediaUsername"],
        "last-updated": "YYYY-MM-DD"
    },

    "page.claim.title": "Obtenir le certificat",
    ...
}
```

### Step 3: Register the language in `helpers/i18n.php`

Open `helpers/i18n.php` and find `$GLOBALS['dcw_supported_languages']`:

```php
$GLOBALS['dcw_supported_languages'] = [
    'en' => ['name' => 'English', 'dir' => 'ltr'],
    'es' => ['name' => 'Español', 'dir' => 'ltr'],
    'fr' => ['name' => 'Français', 'dir' => 'ltr'],  // ← Add this
];
```

> For Right-to-Left languages like Arabic or Urdu, use `'dir' => 'rtl'`.

### Step 4: Run the test suite to verify

```bash
php tests/test_i18n.php
```

All tests should pass, including the key-count sync check.

---

## 📝 Translation Tips

| Symbol | Meaning |
| :----- | :------ |
| `{name}` | A runtime placeholder — keep it exactly as-is in your translation |
| `{year}` | Will be replaced with the current year (e.g. 2025) |
| `{id}`   | Will be replaced with a certificate ID |
| `{org}`  | Will be replaced with the organization name |

**Example:**
```json
"page.verify.not-found-detail": "No pudimos encontrar un certificado con ID: {id}."
```
The `{id}` part stays unchanged — the app fills it in at runtime.

---



## 🌍 Translatewiki.net

Once your translation is merged, our repository is registered on [translatewiki.net](https://translatewiki.net).  
Global Wikimedia community translators can contribute directly through the web interface, and a bot will automatically open a Pull Request with their translations.

---

## 📋 Keys Reference

Every message key is documented in [`qqq.json`](./qqq.json) with a full description of its purpose and which placeholders it uses. Consult it whenever a key's meaning is unclear.

---

## ✅ Checklist Before Submitting Your PR

- [ ] `i18n/XX.json` exists and is valid JSON (run `php scratch/validate_json.php`)
- [ ] All keys from `en.json` are present in your translation file (no extras, no missing)
- [ ] Language is registered in `helpers/i18n.php` → `$GLOBALS['dcw_supported_languages']`
- [ ] `php tests/test_i18n.php` passes all tests
- [ ] `php -l` shows no syntax errors
- [ ] Your Wikimedia username is listed in `@metadata.authors`

---

Thank you for helping make the DCW Certificate Portal accessible to communities around the world! 🌍
