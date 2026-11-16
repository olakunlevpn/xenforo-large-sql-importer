# [Olakunlevpn] Large SQL Importer & Chunk Merger for XenForo

Stream large SQL dumps into MySQL and merge encrypted backup chunks directly in your browser.

[![XenForo Version](https://img.shields.io/badge/XenForo-2.3.0%2B-blue.svg)](https://xenforo.com)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![XenForo Resource](https://img.shields.io/badge/Download-XenForo%20Resource-orange.svg)](https://xenforo.com/community/resources/)

Official companion utility for **[Olakunlevpn] Telegram Free Cloud Backup**.

---

## Why this exists

Web-based database tools choke on multi-gigabyte dumps. You run into 504 gateway timeouts or out-of-memory errors halfway through a big table. Worse, if your database crashed and lost its tables, you can't even log into XenForo Admin CP to fix it.

This tool runs as a standalone script at `install/large-sql-importer.php`. It needs zero existing database tables and under 16 MB of PHP memory. It streams queries line by line so execution limits don't kill the import. No timeouts. If your connection drops, it saves your exact spot so you can resume right where you left off.

It also works alongside **[Olakunlevpn] Telegram Free Cloud Backup**. When Telegram splits your backup into chunks or encrypts it with AES-256, upload the parts. The tool puts them back together and decrypts them on your server so you don't have to fiddle with command line scripts.

---

## What it handles

* **Large SQL files:** Reads dumps in small 4 MB chunks. XenForo search index rows and compiled templates don't get truncated.
* **Gzip dumps:** Imports `.sql.gz` archives on the fly without decompressing them to disk first.
* **Chunk merger:** Joins `.part01`, `.001`, and `.enc` files back into a single dump.
* **AES-256 decryption:** Decrypts password-protected Telegram backup chunks using server OpenSSL or pure PHP.
* **Crash recovery:** Saves byte progress in JSON state files. Hit resume if your browser closes.
* **Config editor:** Tests and updates your MySQL credentials in `src/config.php` without touching a text editor.

---

## Quick Start (Restoring a Forum)

1. Upload the `upload/` folder contents to your XenForo root directory.
2. Drop your `.sql` dump or backup chunks into `internal_data/sql_import/`.
3. Open `https://yourforum.com/install/large-sql-importer.php` in your browser.
4. Check your database connection under the **Database Configuration** tab.
5. If your backup has split or encrypted chunks, merge them on the **Chunk Merger & Decryptor** tab.
6. Click **Import** on the **SQL Importer** tab and let it run.

When you're done, delete `install/large-sql-importer.php` and your dump files from `internal_data/sql_import/` so nobody else can touch them.

---

## Repository Structure

```
upload/
├── install/
│   ├── large-sql-importer.php
│   └── sql-importer.php
└── src/
    └── addons/
        └── Olakunlevpn/
            └── LargeSqlImporter/
                ├── addon.json
                ├── build.json
                ├── hashes.json
                ├── icon.png
                ├── README.md
                ├── Runner.php
                ├── Setup.php
                ├── _data/
                └── _output/
LICENSE
README.md
```

---

## Requirements

* XenForo 2.3.0+
* PHP 7.4 through 8.3
* MySQL 5.7+ or MariaDB 10.3+
* PHP Extensions: `pdo_mysql`, `openssl`, `zlib`

---

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.

---

## Support

* Product Information: [https://maylancer.org/products](https://maylancer.org/products)
* Help Desk: [https://maylancer.org/support](https://maylancer.org/support)
* Companion Add-on: [Olakunlevpn Telegram Free Cloud Backup](https://github.com/olakunlevpn/olakunlevpn-telegram-free-cloud-backup)
