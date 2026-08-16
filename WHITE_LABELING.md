# Adapting the Portal for Your Organization

The **DCW Digital Certification Portal** is built to be a fully adaptable, white-label solution. Any community, educational institution, or Wikimedia affiliate can fork this repository and instantly customize it for their own brand—**without writing any HTML or PHP code**, and **without causing merge conflicts** when pulling future updates.

Follow these 5 simple steps to adopt the portal for your organization:

---

## 0. Install Dependencies

Before configuring your portal, you must install the required PHP libraries (like the `.env` parser).

1. Ensure you have [Composer](https://getcomposer.org/) installed on your server.
2. Run the following command in the root of your project:
   ```bash
   composer install
   ```

---

## 1. Customizing Links (The `.env` File)

To ensure you can pull upstream updates without merge conflicts, all organization-specific URLs are decoupled from the user interface. 

Create a `.env` file in the root of your project and configure your organization's links:

```env
# Core Links
ORG_URL_HOME="https://wikimedia.es/"
ORG_URL_ABOUT="https://wikimedia.es/Acerca"
ORG_URL_PROGRAMS="https://wikimedia.es/Programas"
ORG_URL_PARTNERSHIPS="https://wikimedia.es/Socios"
ORG_URL_NEWS="https://wikimedia.es/Noticias"
ORG_URL_VISION="https://wikimedia.es/Vision"

# Support Links
ORG_URL_SUBSCRIBE="https://wikimedia.es/Suscribir"
ORG_URL_MEMBERSHIP="https://wikimedia.es/Membresia"
ORG_URL_POLICY="https://wikimedia.es/Politica"
ORG_URL_CONTACT="https://wikimedia.es/Contacto"
ORG_EMAIL_MODERATOR="moderator@wikimedia.es"

# Social Links
ORG_URL_MASTODON="https://mastodon.social/@yourorg"
ORG_URL_FACEBOOK="https://facebook.com/yourorg"
ORG_URL_INSTAGRAM="https://instagram.com/yourorg"
ORG_URL_LINKEDIN="https://linkedin.com/company/yourorg"
ORG_URL_TWITTER="https://twitter.com/yourorg"
ORG_URL_YOUTUBE="https://youtube.com/@yourorg"
```

The portal will automatically inject these URLs into the navigation bar, footer, and verification error emails.

---

## 2. Branding (Logo & Name)

### The Logo
Navigate to the `assets/` directory and replace the default logo:
1. Delete `assets/DCW_logo.png`.
2. Upload your own organization's logo.
3. **Important:** Name your file exactly `DCW_logo.png` so the UI picks it up automatically (or manually update the path in `index.php`, `verify.php`, and `success.php`).

### The Organization Name & Footer Text
The organization name and footer description are powered by the translation engine.
Open `i18n/en.json` and update the following keys:

```json
"site.name": "Wikimedia España",
"footer.blurb": "Wikimedia España is an independent local chapter of the Wikimedia Foundation..."
```

---

## 3. Changing the Default Language

If your primary audience does not speak English, you can change the default portal language so users don't have to manually select it.

1. Create a translation bundle for your language (e.g., `i18n/es.json`). *(See `i18n/README.md` for instructions)*
2. Open `helpers/i18n.php`.
3. Update the `I18N_DEFAULT_LANG` constant to your language code:

```php
// Change 'en' to your language code
define('I18N_DEFAULT_LANG', 'es'); 
```

The portal will now instantly load in your chosen language for all new visitors.

---

## 4. Setup Database & Mailing

Finish up by configuring the standard application secrets in your `.env` file:

```env
# Database Credentials
DB_HOST="localhost"
DB_NAME="certificate_system"
DB_USER="root"
DB_PASS="your_secure_password"

# SMTP for Email Delivery
SMTP_HOST="smtp.mailtrap.io"
SMTP_USER="your_smtp_user"
SMTP_PASS="your_smtp_pass"
SMTP_PORT="2525"
```

🎉 **That's it! Your white-labeled certification portal is ready to launch.**
