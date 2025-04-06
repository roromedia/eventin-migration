# Eventin Pro 3.4.x to 4.x Ticket Migration Script

## Purpose

This PHP script is designed to migrate event ticket data stored in WordPress post meta when upgrading from Eventin Pro version 3.4.x to version 4.x.

## The Problem

Eventin Pro version 4.x introduced significant changes to the data structure for ticket variations, particularly adding fields for ticket start/end dates and times (`start_date`, `end_date`, `start_time`, `end_time`, `date_range`). Unfortunately, the plugin developer (ThemeWinter) did not provide an automatic database migration path for existing ticket data created with version 3.4.x. Their recommendation was essentially to recreate tickets from scratch.

This script bridges that gap by programmatically updating the old ticket data structure (`etn_ticket_variations` stored as serialized data in `wp_postmeta`) to include the necessary fields required by version 4.x.

## What the Script Does

1.  **Connects** to the WordPress database.
2.  **Identifies** all posts with the custom post type `etn` (the default for Eventin events).
3.  **Checks `etn_end_date`:** For each event, it ensures the `etn_end_date` post meta field exists and has a value. If the key exists but is empty, or if the key doesn't exist at all, it sets the `etn_end_date` value to be the same as the event's `etn_start_date`.
4.  **Processes Tickets:** It retrieves the `etn_ticket_variations` meta value (which contains a serialized array of tickets).
5.  **Updates Ticket Structure:** For each individual ticket within the array:
    *   Adds the new required keys: `start_date`, `end_date`, `start_time`, `end_time`, `date_range`, `etn_enable_ticket`.
    *   Sets `start_date` and `end_date` based on the event's `etn_start_date` and `etn_registration_deadline`:
        *   **Past Events:** If the event's start date (`etn_start_date`) has already passed, both the ticket `start_date` and `end_date` are set to the event's start date.
        *   **Future Events:** If the event's start date is in the future, the ticket `start_date` is set to a configurable default (e.g., `2025-01-01`), and the ticket `end_date` is set to the event's registration deadline (`etn_registration_deadline`), if available and valid. If the deadline is missing or invalid, a configurable fallback end date (e.g., `2025-12-31`) is used.
    *   Sets `start_time` and `end_time` to configurable defaults (e.g., `12:00 AM` and `11:55 PM`).
    *   Sets `date_range` to a default value (e.g., `Invalid Date`).
    *   Sets `etn_enable_ticket` to `true`.
    *   **Corrects Time:** Fixes a specific issue where some older `etn_end_time` values might be stored incorrectly as `12:xx AM` by changing them to `12:xx PM`.
6.  **Saves Changes:** Serializes the updated ticket array and saves it back to the `wp_postmeta` table.

## How to Use

1.  **Backup Your Database:** Before running any migration script, **always create a full backup** of your WordPress database.
2.  **Configure:** Open the `migrate.php` script and review the settings in the `CONFIGURATION SETTINGS` section:
    *   Update the `$dbHost`, `$dbName`, `$dbUser`, `$dbPass`, and `$tablePrefix` variables to match your WordPress database connection details.
    *   Adjust the `const` values (like `DEFAULT_FUTURE_EVENT_TICKET_START_DATE`, `DEFAULT_TICKET_END_TIME`, etc.) if the provided defaults don't suit your needs.
3.  **Upload:** Upload the `migrate.php` script to a location accessible by your web server (e.g., the WordPress root directory, although removing it after use is recommended for security).
4.  **Run:** Execute the script. This can usually be done via SSH:
    *   Navigate to the directory where you uploaded the script.
    *   Run the command: `php migrate.php`
    *   (If using DDEV, use `ddev ssh` first, then navigate and run `php migrate.php` inside the container).
5.  **Verify:** Check your events and tickets within the WordPress admin area (after ensuring Eventin Pro 4.x is active) to confirm the migration worked as expected. Pay attention to the ticket sale start/end dates and times.
6.  **Remove Script:** Delete `migrate.php` from your server once you have confirmed the migration was successful.

## Disclaimer

This script was developed based on observed data structures and specific needs. While it aims to be robust, use it at your own risk. Always back up your data before running migration tasks. It only modifies specific meta fields related to Eventin tickets and dates; it does not alter other event data.

---

*If this script saved you time and effort, consider [buying me a coffee](https://buymeacoffee.com/roromedia) to support future development!*