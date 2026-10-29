# Backup & Restore Guide

For the shop owner / whoever runs the server. Everything is on **Audit & Monitoring → Backups** (admin only).
Backups are made by the application itself (no `mysqldump` needed).

## 1. One-time setup

1. **Encryption key (recommended)** – backups contain customer, staff and financial data:
   ```
   php artisan backup:key
   ```
   Put the printed value in `.env` as `BACKUP_ENCRYPTION_KEY=base64:...`, run `php artisan config:clear`,
   and **store a second copy of the key outside the server** (password manager / printed). Without the key an encrypted
   backup cannot be opened. Never commit `.env` or the key to git (`.env` is in `.gitignore`).
2. **Scheduler** – Windows Task Scheduler, every minute: program `C:\xampp\php\php.exe`,
   arguments `C:\xampp\htdocs\MobileShop\artisan schedule:run` (Linux: cron `* * * * *`). It runs:
   | When | Command | What |
   |---|---|---|
   | every minute | `backup:scheduled` | automatic backup when it is due (settings on the Backups page) |
   | every minute | `audit:seal` | adds new audit entries to the hash chain |
   | every 5 min | `monitor:check` | health checks, alerts |
   | Sunday 03:00 | `backup:verify` | checks the newest backup with a test restore |
   | 03:30 daily | `audit:verify` | detects changed/deleted audit entries |
   | 04:00 daily | `monitor:integrity` | `finance:audit` + `inventory:audit` |
3. **Automatic backups** – on the Backups page: switch on *Back up automatically*, choose how often (every 6 h / 12 h /
   day / week), the time, and how many automatic backups to keep. If the computer is off at that time, the backup runs
   when it is switched on again.
4. **Alerts** – `.env`: `ALERT_EMAILS=owner@example.com` and real SMTP settings. Monitoring warns when automatic
   backups are off, and raises a critical alert when a backup fails or the last one is too old.

## 2. What a backup contains

- A compressed SQL copy of every table (products, sales, customers, accounts, settings, users, audit log ...),
  read as one consistent snapshot, so a sale during the backup cannot leave it half-written.
- Left out: login sessions, cache, the job queue and password-reset tokens.
- Uploaded images are files, not database rows, so they are **not** in a database backup. Copy `storage/app/public` separately.
- Stored in `storage/app/private/backups` (outside `public/`, files `0600`, `.htaccess` denies web access),
  each with a `.json` manifest (SHA-256, table and row counts).

## 3. Kinds of backups and how long they are kept

| Type | Made by | Deleted |
|---|---|---|
| Manual | *Back up now* (optional note) or `php artisan backup:run` | only when you delete it |
| Automatic | the schedule | older than the "keep the last N" setting |
| Uploaded | *Upload a backup* or `php artisan backup:import FILE` | only when you delete it |
| Before restore | automatically, just before every restore | only when you delete it |

**Offsite copy:** download backups regularly (Download asks for your password again) and keep them on another device
(pendrive / NAS / Google Drive). A backup on the same disk does not survive a disk failure, theft or ransomware.

## 4. Checking a backup

**Check** (clipboard button, or `php artisan backup:verify ID`):
1. recomputes the SHA-256 checksum,
2. reads every statement and confirms it is allowed,
3. restores the backup into a separate temporary database (`<db>_restore_check`), compares every table's row count,
   and deletes that database. Live data is not touched.

The newest backup is checked automatically every Sunday.

## 5. Uploading a backup

`.sql` or `.sql.gz` from this page or from MySQL / phpMyAdmin (or an encrypted backup from this page). Before it is
accepted, every statement is checked: only `CREATE TABLE`, `DROP TABLE`, `INSERT`, key/column `ALTER TABLE` and session
`SET` are allowed. Triggers, procedures, views, `GRANT`, `DROP DATABASE`, `UPDATE`/`DELETE`, `INSERT ... SELECT`,
other databases' tables, FEDERATED tables and file reads/writes make the whole file rejected (logged in the audit log).
The file must contain the `users` and `migrations` tables. Uploading never restores anything.

## 6. Restore

From the page: press the **Restore** button on a backup, confirm your password, type `RESTORE`.
From the console: `php artisan backup:restore ID` (type `RESTORE`).

What happens:
1. The backup's checksum and every statement are checked; a file with less than half of the current tables
   (partial export) is refused. Nothing has changed yet.
2. A **Before restore** backup of the current data is taken. If that fails, nothing is changed.
3. Tables are restored to the backup's state; tables that did not exist then are removed. Login sessions, the job queue
   and the backup list itself are not touched.
4. `php artisan migrate` runs automatically so an older backup matches the current software version; the audit-log
   protection triggers are re-created; caches are cleared.
5. Afterwards run `php artisan finance:audit` and `php artisan inventory:audit`.

Everything entered after the backup time is gone (it is still inside the *Before restore* backup). Ask other users to
stop working during the restore. If a restore stops halfway, restore the *Before restore* backup to go back.

**New computer / server lost:** install the software, restore `.env` with the **same `APP_KEY` and
`BACKUP_ENCRYPTION_KEY`**, run `php artisan migrate`, log in, upload the backup file on the Backups page (or
`php artisan backup:import D:\backup-....sql.gz.enc`) and restore it. With a different `APP_KEY`, stored SMS/WhatsApp
credentials cannot be read and `audit:verify` reports every entry as changed.

## 7. Checklist

- [ ] Monitoring shows **Backup: OK** (automatic on, recent backup, checked in the last 8 days).
- [ ] The newest backup shows **passed** under Check.
- [ ] A downloaded copy exists outside this computer and opens (its SHA-256 matches the `.json`; Windows: `certutil -hashfile FILE SHA256`).
- [ ] Once a month: on another computer, upload the offsite copy and press **Check**.
- [ ] The encryption key copy outside the server still opens a backup.

## 8. Security notes

- Only users with `manage backups` (admin by default) see the page; download and restore ask for the password again;
  every backup, download, upload (also refused ones), check, restore and delete is in the audit log.
- No passwords, API secrets, tokens, NID or payment-account numbers are written to the audit log or the error list.
- Audit entries cannot be edited or deleted (no routes, model guard, MySQL triggers, HMAC hash chain checked by `audit:verify`).
- Production secrets (`.env`, keys, backup files) must never be committed to the repository.
