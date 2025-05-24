
# WHMCS-Database-Structure-Fixer

**🔧 A WHMCS MySQL Repair & Optimization Tool**
**✍️ by [Shahid Malla](https://shahidmalla.dev) – 📧 [life@shahidmalla.dev](mailto:life@shahidmalla.dev)**

---

## 📌 Overview

**WHMCS-Database-Structure-Fixer** is a robust PHP-based tool designed to fix structural and integrity issues in WHMCS MySQL databases. This includes handling common MySQL errors such as:

* `Integrity constraint violation: 1062 Duplicate entry '0' for key`
* `SQLSTATE[23000]: Integrity constraint violation: Duplicate entry '1' for key 'tblconfiguration.PRIMARY'`
* And many more similar database-level issues.

---

## 📋 Usage Instructions

### 🖥️ Command Line

1. **Save the script** as `whmcs_db_fixer.php`
2. **Upload** to the root directory of your WHMCS installation.
3. **Run it using one of the modes below:**

```bash
# 🔧 Normal execution
php whmcs_db_fixer.php

# 🧪 Dry run (no actual changes made)
php whmcs_db_fixer.php --dry-run

# ⚡ Quick fix (core WHMCS tables only)
php whmcs_db_fixer.php --quick-fix

# 🚨 Emergency mode (for broken or corrupted DBs)
php whmcs_db_fixer.php --emergency

# 🤫 Silent mode (minimal output)
php whmcs_db_fixer.php --silent
```

---

### 🌐 Web Browser

Upload the file to your server and visit:

```text
https://yourdomain.com/whmcs_db_fixer.php
```

---

## 🚀 Features

* ✅ **Auto-reconnects** to handle `MySQL server has gone away` errors
* 🔁 **Retry mechanism**: Attempts up to 5 times for failed queries
* 📦 **Batch processing** to avoid memory/time overloads
* 🛠️ **Emergency recovery** mode for critical issues
* 📊 **Progress tracking** with percent completion
* 💾 **Automatic backups** before destructive changes
* 🧾 **Detailed logs** for all operations
* 📄 **Professional HTML reports** generated after execution

---

## 🧯 Solves Issues Like:

* **Duplicate entry errors**
* **Corrupt or missing primary keys**
* **Invalid constraints**
* **Misconfigured `tblconfiguration` data**
* **Broken foreign key links**

---

## 👨‍💻 Author

**Shahid Malla**
📧 [life@shahidmalla.dev](mailto:life@shahidmalla.dev)
🌐 [https://shahidmalla.dev](https://shahidmalla.dev)

---

## ⚠️ Disclaimer

> **Use at your own risk!** Always back up your database before running repair scripts. This tool is intended for developers and system administrators who understand the risks of database modification.

