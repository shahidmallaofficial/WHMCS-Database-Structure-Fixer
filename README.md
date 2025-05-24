# WHMCS-Database-Structure-Fixer

**🔧 A WHMCS MySQL Repair & Optimization Tool**
**✍️ Written by [Shahid Malla](https://shahidmalla.dev) – 📧 [life@shahidmalla.dev](mailto:life@shahidmalla.dev)**

---

## 📌 Overview

**WHMCS-Database-Structure-Fixer** is a robust PHP-based utility designed to detect and repair common structural problems in your WHMCS MySQL database. It helps resolve:

* 🔁 **Duplicate entry** errors (`1062 Duplicate entry '0' for key`)
* ❌ **Primary key conflicts** (`Duplicate entry '1' for key 'tblconfiguration.PRIMARY'`)
* 🧱 **Constraint violations**
* 💥 **Emergency database crashes**

---

## ⚙️ Setup Instructions

### 1. 🔽 **Download and Save the Script**

Save the PHP script as:

```bash
whmcs_db_fixer.php
```

Place it in the **root directory of your WHMCS installation**.

---

### 2. ✏️ **Edit Database Connection Settings**

Open `whmcs_db_fixer.php` in any code editor and **update the following section** with your actual WHMCS database credentials:

```php
$emergencyFixer = new WHMCSDBFixer([
    'host' => 'localhost',
    'username' => 'shahidmaladbusername',
    'password' => 'shahidmallapassword',
    'database' => 'shahidmalladb',
    'dry_run' => false,
    'verbose' => true,
    'max_retries' => 3,
]);
```

> 📁 You can find your real database login details inside your WHMCS `configuration.php` file (located in your WHMCS root directory).

---

## 🚀 Usage

### 🖥️ Command Line Options

```bash
# 🔧 Run normally
php whmcs_db_fixer.php

# 🧪 Test mode (no changes made)
php whmcs_db_fixer.php --dry-run

# ⚡ Quick fix for essential WHMCS tables
php whmcs_db_fixer.php --quick-fix

# 🚨 Emergency repair mode
php whmcs_db_fixer.php --emergency

# 🤫 Silent operation (no output)
php whmcs_db_fixer.php --silent
```

---

### 🌐 Web Interface

Just access the file in your browser after uploading:

```
https://yourdomain.com/whmcs_db_fixer.php
```

---

## 🔑 Key Features

* ✅ **Auto-reconnect** for `MySQL server has gone away` errors
* 🔁 **Retry logic** (up to 5 times)
* 🧹 **Batch-based processing** to avoid timeouts
* 💾 **Automatic backups** before data modifications
* 🛠️ **Emergency fixer** mode for critical DB failures
* 📊 **Progress tracker** in CLI or browser
* 🧾 **Detailed logs and HTML report output**

---

## 🛠️ Fixes Common WHMCS Errors Like:

* `Integrity constraint violation: 1062 Duplicate entry`
* `SQLSTATE[23000]: Integrity constraint violation`
* `Duplicate entry '0' for key`
* `Duplicate entry '1' for key 'tblconfiguration.PRIMARY'`

---

## 👨‍💻 Author

**Shahid Malla**
📧 [life@shahidmalla.dev](mailto:life@shahidmalla.dev)
🌐 [https://shahidmalla.dev](https://shahidmalla.dev)

---

## ⚠️ Disclaimer

> 💡 **Always take a full backup of your WHMCS database before using this tool!**
> This tool is intended for developers and system administrators familiar with WHMCS and MySQL operations. Improper usage may result in data loss.
