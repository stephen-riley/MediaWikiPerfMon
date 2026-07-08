# MediaWikiPerfMon

**MediaWikiPerfMon** is a modern MediaWiki extension that provides real-time server health and performance monitoring directly within MediaWiki. It adds a premium, collapsible metrics dashboard under a new Special Page: `Special:ServerHealth`.

---

## Features

- **CPU Load & Uptime Panel:** Shows 1, 5, and 15-minute load averages alongside system uptime.
- **Memory Usage Panel:** Displays total, used, and available system memory with an interactive, colored percentage progress bar.
- **Database Status Panel:** Tracks connected threads, peak active connections, slow query count, and database server uptime.
- **Disk Status Panel:** Monitored via `/dev` mounted partitions, displaying usage percentages, mount paths, and remaining sizes, with amber/red alerts based on disk usage thresholds.
- **Collapsible Slow Queries Panel:** Lists detailed slow queries (timestamp, duration, database, and statement syntax) on-demand, securely hidden by default.
- **Responsive Premium UI:** Fully compatible with vector/modern MediaWiki skins, featuring smooth gradients, cards layout, hover effects, and automatic dark-mode styling.

---

## Requirements

- **MediaWiki:** version `1.35` or higher (fully tested on MediaWiki `1.40`+).
- **Operating System:** Linux/Unix (required for `/proc` parsing, `free -m`, and `df -h`).
- **PHP:** `shell_exec()` must be enabled in your `php.ini` configuration.
- **Database:** MySQL or MariaDB.

---

## Installation

### 1. Place the Extension Files
Clone or copy this extension directory into your MediaWiki installation's `extensions/` directory:

```bash
cd /var/www/mediawiki/extensions/
git clone https://github.com/your-username/MediaWikiPerfMon.git MediaWikiPerfMon
```

### 2. Enable the Extension
Add the following line to the bottom of your `LocalSettings.php` file:

```php
wfLoadExtension( 'MediaWikiPerfMon' );
```

### 3. Verify Installation
Navigate to your wiki and visit `Special:ServerHealth` (or find "Server Health" in the list of Special Pages).

---

## Optional: Configuring the Database Slow Queries Panel

By default, standard database users do not have permissions to read logs. If you click **Show Slow Queries List**, you will see instructions on how to configure this on your database server.

To display slow queries:

### 1. Grant SELECT Privileges to the Wiki User
Log in to your MySQL/MariaDB server as root and grant SELECT permission on the `mysql.slow_log` table to your MediaWiki database user:

```sql
GRANT SELECT ON mysql.slow_log TO 'your_wiki_db_user'@'localhost';
FLUSH PRIVILEGES;
```
*(Replace `your_wiki_db_user` with the database user defined in your `LocalSettings.php`.)*

### 2. Enable Slow Query Table Logging
Ensure your database server has slow query logging enabled and outputting to the log table by running these queries as root:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL log_output = 'TABLE';
-- Optional: Set threshold for what counts as a slow query (in seconds)
SET GLOBAL long_query_time = 2.0; 
```

---

## Security Architecture

This extension was designed with safety and isolation in mind:

- **Strict Input Constraints:** All system shell commands (`cat /proc/loadavg`, `free -m`, and `cat /proc/uptime`) are completely hardcoded.
- **Input Isolation:** The PHP logic explicitly ensures that no URL query parameters, HTTP requests, or user inputs can ever interact with or modify the `shell_exec()` calls.
- **Graceful Failure:** If any shell utilities are blocked or DB query permissions are missing, the UI degrades gracefully with descriptive labels and helper guides instead of raising PHP fatal errors.

---

*This extension was written with [Google Antigravity 2](https://antigravity.google).*
