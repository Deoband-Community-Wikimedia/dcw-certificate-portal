# PHP Certificate Management System

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.0-8892BF.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![Maintenance](https://img.shields.io/badge/Maintained%3F-yes-green.svg)](https://github.com/Zaidusyy)

**DCW's Digital Certification Portal** is a highly secure, lightweight, and monolithic PHP application designed to bulk generate, distribute, and cryptographically verify digital certificates. Originally designed to issue verifiable credentials to participants in DCW events, this application allows communities, educational institutions, and organisations to handle end-to-end credential workflows without relying on expensive SaaS infrastructure like Credly.

---

## Core Features

- **Drag-and-Drop Visual Editor:** A powerful built-in PDF editor. Upload a blank PDF template and visually map dynamic text fields (Participant Name, Credential ID, Issue Date) and a customizable, brand-colored QR code directly onto the canvas.
- **Bulk CSV Processing:** Effortlessly import hundreds of participants at once using a standardized CSV upload system.
- **Event & Role Isolation:** Manage multiple events simultaneously. Create distinct roles (e.g., Speaker, Volunteer, Attendee) within each event, allowing for role-specific PDF templates.
- **Public Verification Portal:** Includes a public-facing validation endpoint. Anyone can scan a certificate's QR code to instantly verify its authenticity against the central database.
- **1-Click LinkedIn Integration:** Generated certificates provide participants with a dynamic "Add to LinkedIn Profile" button that securely pre-fills their credential data.
- **Enterprise-Grade Security:** Hardened against SQL Injection (PDO prepared statements), CSRF attacks (strict token verification on state-changing endpoints), Session Fixation, and Brute-Force authentication attempts (server-side delays).
---

## Architecture & Tech Stack

This platform is intentionally built without heavy frameworks to ensure maximum portability across standard shared-hosting environments (cPanel/Hostinger) and minimal dependency overhead.

- **Backend Logic:** PHP 8.x (Vanilla)
- **Database Layer:** MySQL / MariaDB (via PDO)
- **PDF Generation Engine:** FPDI & TCPDF
- **Frontend UI:** HTML5, CSS3, Vanilla JavaScript (Zero-build pipeline)

---

## Quick Start Guide

### 1. Requirements
Ensure your server or local environment has PHP 8.0+ installed with the `pdo_mysql`, `gd`, and `mbstring` extensions enabled.

### 2. Clone & Setup
Clone the repository to your server's public directory (or `htdocs`):
```bash
git clone https://github.com/Deoband-Community-Wikimedia/dcw-certificate-portal.git
cd dcw-certificate-portal
```

### 3. Database Initialization
1. Create a new, empty MySQL database.
2. Import the `database.sql` schema file located in the root directory. This provisions the necessary tables and creates the default administrator account.

### 4. Configuration
Duplicate the example configuration file:
```bash
cp config.example.php config.php
```
Open `config.php` and map it to your newly created database:
```php
$host = 'localhost';
$db   = 'your_database_name';
$user = 'your_database_user';
$pass = 'your_database_password';
```

### 5. File System Permissions
The application requires write access to generate and store PDF assets. Ensure the `uploads/` directory has proper write permissions:
```bash
chmod -R 755 uploads/
```

### 6. First Login
Boot up your server (or run `php -S localhost:8000` locally) and navigate to the admin dashboard:
- **URL:** `http://localhost:8000/admin/login.php`
- **Default Username:** `admin`
- **Default Password:** `password123`

> **[CAUTION] Security Warning:** You must immediately navigate to the **"Manage Users"** tab to rotate the default administrator credentials before exposing the portal to public traffic.

---

## Production Security

For live production deployments, you must prevent public HTTP access to sensitive configuration files.
An `.htaccess` file is included in this repository to automatically block access to `config.php` and `.git/` directories on Apache servers. If you are deploying on Nginx, you must manually implement equivalent `deny all;` blocks in your server block configuration.

---

## Contributing

We welcome pull requests, bug reports, and feature ideas. To contribute:
1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/your-amazing-feature`).
3. Commit your changes (`git commit -m 'feat: added something amazing'`).
4. Push to the branch (`git push origin feature/your-amazing-feature`).
5. Open a Pull Request.

Please ensure all new code adheres to the existing security paradigms (strictly use PDO for database queries and include CSRF tokens on all `POST` forms).

---

## Author

**[Zaid Sayyed](https://github.com/Zaidusyy)** 
**and other DCW volunteers** 

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.
