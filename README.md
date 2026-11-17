# [Olakunlevpn] Large SQL Importer & Chunk Merger

High-performance streaming SQL dump importer and chunked archive reassembler built for XenForo disaster recovery, server migrations, and automated pipelines.

Official companion utility for [**[Olakunlevpn] Telegram Free Cloud Backup**](https://xenforo.com/community/resources/olakunlevpn-telegram-free-cloud-backup.11018/).

---

### Key Features

1. **Standard XenForo Add-on Integration:** 
   Fully integrated into the XenForo 2.3 ecosystem. Access the tool directly from your Admin Control Panel under **Tools → Large SQL Importer** (`admin.php?large-sql-importer/`).

2. **Dedicated Headless CLI Command:**
   Run imports and chunk mergers natively via the XenForo command-line interface:
   ```bash
   php cmd.php xf-addon:large-sql-importer --action=list
   php cmd.php xf-addon:large-sql-importer --action=import --file=forum_dump.sql
   php cmd.php xf-addon:large-sql-importer --action=merge --base-name=backup_2026.sql
   ```

3. **Disaster Recovery Standalone Mode:**
   If your XenForo database tables are corrupt, dropped, or completely offline and XenForo cannot boot, execute the standalone runner without framework dependencies:
   ```bash
   php src/addons/Olakunlevpn/LargeSqlImporter/standalone.php
   ```
   *(Optional web access: copy `standalone.php` to your web root or install directory to run in a browser).*

4. **Ultra-Low Memory Streaming:**
   Streams multi-gigabyte `.sql` and compressed `.sql.gz` dumps in micro-batches under 16 MB PHP RAM. Automatically handles foreign keys, unique checks, multi-line statements, and custom DELIMITER blocks. If connection is interrupted, state is preserved for zero-loss resumption.

5. **Encrypted Chunk Merger:**
   Automatically detects, verifies, concatenates, and decrypts split `.part*` and `.enc` backup chunks created by [**[Olakunlevpn] Telegram Free Cloud Backup**](https://xenforo.com/community/resources/olakunlevpn-telegram-free-cloud-backup.11018/) using AES-256-CBC with PBKDF2 salt derivation.

---

### Quick Start

#### Installation (XenForo Standard)
1. Download the release archive (`Olakunlevpn-LargeSqlImporter-1.0.0.zip`).
2. In your XenForo Admin Control Panel, navigate to **Add-ons → Install / upgrade from archive** and upload the ZIP.
3. Alternatively, extract and upload the `upload/` folder contents to your forum root, then click **Install** under **Add-ons**.

#### Importing SQL Dumps
1. Place your SQL dump file (e.g. `forum_backup.sql` or `forum_backup.sql.gz`) into:
   ```
   internal_data/sql_import/
   ```
2. Navigate to **Tools → Large SQL Importer** in Admin CP and click **Launch Large SQL Importer**, or execute from terminal:
   ```bash
   php cmd.php xf-addon:large-sql-importer --action=import --file=forum_backup.sql
   ```

#### Merging Telegram Backup Chunks
1. Place split chunk files (e.g. `backup.sql.gz.part01`, `backup.sql.gz.part02`, or `.enc`) into `internal_data/sql_import/`.
2. In Admin CP or via CLI, run:
   ```bash
   php cmd.php xf-addon:large-sql-importer --action=merge --base-name=backup.sql.gz --key=YOUR_AES_KEY
   ```
3. Once merged, import the resulting file directly.

---

### Requirements

* **XenForo:** 2.3.0+
* **PHP:** 8.0+ (PHP 8.1, 8.2, 8.3 fully supported)
* **MySQL / MariaDB:** MySQL 5.7+ / 8.0+ or MariaDB 10.3+
* **PHP Extensions:** `mysqli` / `pdo_mysql`, `openssl` (for AES-256 decryption), `zlib` (for `.gz` dumps)

---

### Developer & Support

* **Developer:** Olakunlevpn
* **Website:** [https://maylancer.org](https://maylancer.org)
* **Support:** [https://maylancer.org/support](https://maylancer.org/support)

---

### License

MIT License. Free for commercial and non-commercial communities.
