# [Olakunlevpn] Large SQL Importer & Chunk Merger

High-performance streaming SQL dump importer and chunked archive reassembler built for XenForo disaster recovery and migrations.

Official companion utility for **[Olakunlevpn] Telegram Free Cloud Backup**.

---

### What it does

Standard tools like phpMyAdmin and Adminer choke on large XenForo dumps due to PHP timeouts, memory exhaustion, or multi-megabyte `xf_search_index` rows. Even worse, if your MySQL server is down or empty, you can't even get into Admin CP.

This standalone utility solves that:

1. **Zero Database Dependency:** Runs standalone at `install/large-sql-importer.php`. It boots with 0 tables in your database and uses XenForo's native installer design (`install.css`).
2. **Ultra-Low Memory Streaming:** Imports multi-gigabyte `.sql` and `.sql.gz` dumps in tiny streaming batches under 16 MB of PHP RAM. If a connection drops, hit **Resume** to pick up at the exact query.
3. **Telegram Backup Companion:** Automatically detects, merges, and decrypts split `.part*` and `.enc` backup chunks created by **Telegram Free Cloud Backup** using your AES-256 key.

---

### Quick Start

1. Upload the add-on files to your XenForo root directory.
2. Put your SQL dump (or Telegram backup chunks) into `internal_data/sql_import/`.
3. Open `https://yourforum.com/install/large-sql-importer.php` in your browser.
   * If using split chunks: switch to **Chunk Merger**, enter your key if encrypted, and click **Merge**.
   * On **SQL Importer**: click **Import** and let it stream into MySQL.
4. Once restored, delete `install/large-sql-importer.php` for security.

---

### Requirements
* XenForo 2.3.0+
* PHP 7.4+ (PHP 8.1 - 8.3 recommended)
* MySQL 5.7+ / 8.0+ / MariaDB 10.3+
* Extensions: `pdo_mysql`, `openssl` (for decryption), `zlib` (for .gz)

---

### License
MIT License. Free for commercial and non-commercial communities.
