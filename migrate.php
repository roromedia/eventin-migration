<?php
/**
 * Migration script to update Eventin Pro (version 3.4.x to 4.x) ticket variations 
 * and related event meta fields in the WordPress database.
 *
 * Problem: Eventin Pro 4.x introduced new ticket date/time fields, but there is 
 * no official migration path from 3.4.x for existing ticket data structure stored 
 * in post meta. This script bridges that gap.
 *
 * What it does:
 * 1. Iterates through all posts with post_type 'etn'.
 * 2. Ensures the 'etn_end_date' post meta key exists, setting it to the 'etn_start_date' 
 *    value if the key exists but is empty, or inserting it if the key doesn't exist.
 * 3. Fetches the serialized 'etn_ticket_variations' data.
 * 4. For each ticket variation:
 *    - Adds new required fields: 'start_date', 'end_date', 'start_time', 'end_time', 
 *      'date_range', 'etn_enable_ticket'.
 *    - Calculates 'start_date' and 'end_date' based on the event's 'etn_start_date' and 
 *      'etn_registration_deadline':
 *          - If event date is in the past: Uses event date for both start and end.
 *          - If event date is in the future: Uses a default start date and the registration 
 *            deadline (or a fallback default) as the end date.
 *    - Sets default start/end times.
 *    - Corrects specific legacy 'etn_end_time' values ("12:xx AM" to "12:xx PM").
 * 5. Saves the updated serialized ticket data back to the database.
 */

// ==================================================
// CONFIGURATION SETTINGS
// ==================================================

// --- Database Credentials ---
// Update these values with your actual database credentials
$dbHost = 'db'; // DDEV database service hostname (or 'localhost', '127.0.0.1')
$dbName = 'db'; // WordPress database name
$dbUser = 'db'; // Database username
$dbPass = 'db'; // Database password
$tablePrefix = 'wp_'; // WordPress table prefix

// --- Migration Defaults ---

// Default start date for tickets of FUTURE events.
// Used if the event's 'etn_start_date' is later than the script execution time.
// Format: YYYY-MM-DD
const DEFAULT_FUTURE_EVENT_TICKET_START_DATE = '2025-01-01';

// Default end date for tickets of FUTURE events.
// Used ONLY IF the event's 'etn_registration_deadline' meta value is missing or empty.
// Format: YYYY-MM-DD
const DEFAULT_FUTURE_EVENT_TICKET_END_DATE_FALLBACK = '2025-12-31';

// Default start time assigned to all migrated tickets.
// Format: H:MM AM/PM
const DEFAULT_TICKET_START_TIME = '12:00 AM';

// Default end time assigned to all migrated tickets (unless corrected below).
// Format: H:MM AM/PM
const DEFAULT_TICKET_END_TIME = '11:55 PM';

// Default value for the 'date_range' field in migrated tickets.
const DEFAULT_TICKET_DATE_RANGE = 'Invalid Date';

// Specific 'etn_end_time' values from v3.4.x that need correction.
// The script will replace "AM" with "PM" for these exact times.
const TIMES_TO_CORRECT_AM_PM = ["12:00 AM", "12:15 AM", "12:30 AM", "12:45 AM"];

// ==================================================
// SCRIPT EXECUTION
// ==================================================

echo "Starting Eventin Pro ticket migration script...\\n";

// Connect to the database
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection established.\n";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Get all posts with post_type 'etn'
$stmt = $pdo->prepare("SELECT ID FROM {$tablePrefix}posts WHERE post_type = 'etn'");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($posts) . " etn posts to process.\n";

// Process each post
foreach ($posts as $post) {
    $postId = $post['ID'];
    echo "Processing post ID: $postId\n";
    
    // Get etn_start_date
    $stmt = $pdo->prepare("SELECT meta_value FROM {$tablePrefix}postmeta 
                           WHERE post_id = ? AND meta_key = 'etn_start_date'");
    $stmt->execute([$postId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $eventDate = $result ? $result['meta_value'] : null;
    
    // Get etn_registration_deadline
    $stmt = $pdo->prepare("SELECT meta_value FROM {$tablePrefix}postmeta 
                           WHERE post_id = ? AND meta_key = 'etn_registration_deadline'");
    $stmt->execute([$postId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $deadlineDate = $result ? $result['meta_value'] : null;
    
    // ---> START REVISED ADDITION 1: Check and UPDATE etn_end_date if empty <---
    if ($eventDate) { // Only proceed if we have a start date
        $stmtEndDate = $pdo->prepare("SELECT meta_id, meta_value FROM {$tablePrefix}postmeta 
                                        WHERE post_id = ? AND meta_key = 'etn_end_date'");
        $stmtEndDate->execute([$postId]);
        $endDateResult = $stmtEndDate->fetch(PDO::FETCH_ASSOC);

        // Check if the key exists but the value is empty
        if ($endDateResult && empty($endDateResult['meta_value'])) {
            $metaIdToEndDate = $endDateResult['meta_id'];
            echo "  etn_end_date is empty. Updating it to etn_start_date ($eventDate).\n";
            $updateStmt = $pdo->prepare("UPDATE {$tablePrefix}postmeta 
                                         SET meta_value = ? 
                                         WHERE meta_id = ?");
            $updateStmt->execute([$eventDate, $metaIdToEndDate]);
        } elseif (!$endDateResult) {
             // Optional: Handle case where the key truly doesn't exist (original INSERT logic)
             echo "  etn_end_date meta key not found. Inserting it with etn_start_date ($eventDate).\n";
             $insertStmt = $pdo->prepare("INSERT INTO {$tablePrefix}postmeta (post_id, meta_key, meta_value) 
                                          VALUES (?, ?, ?)");
             $insertStmt->execute([$postId, 'etn_end_date', $eventDate]);
        }
    }
    // ---> END REVISED ADDITION 1 <---
    
    echo "  Event date: " . ($eventDate ?? 'Not set') . "\n";
    echo "  Deadline date: " . ($deadlineDate ?? 'Not set') . "\n";
    
    // Get etn_ticket_variations
    $stmt = $pdo->prepare("SELECT meta_id, meta_value FROM {$tablePrefix}postmeta 
                           WHERE post_id = ? AND meta_key = 'etn_ticket_variations'");
    $stmt->execute([$postId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        echo "  No ticket variations found for this post. Skipping.\n";
        continue;
    }
    
    $metaId = $result['meta_id'];
    $ticketVariations = unserialize($result['meta_value']);
    
    if (!is_array($ticketVariations)) {
        echo "  Invalid ticket variations data. Skipping.\n";
        continue;
    }
    
    echo "  Found " . count($ticketVariations) . " ticket variations.\n";
    
    // Determine the start and end dates
    $startDate = DEFAULT_FUTURE_EVENT_TICKET_START_DATE; // Use constant
    // Use !empty to handle both null and empty strings from the database for deadline
    $endDate = !empty($deadlineDate) ? $deadlineDate : DEFAULT_FUTURE_EVENT_TICKET_END_DATE_FALLBACK; // Use constant
    
    // If event date is in the past, use event date as start date
    $eventTimestamp = $eventDate ? strtotime($eventDate) : false;
    if ($eventTimestamp !== false && $eventTimestamp < time()) {
        $startDate = $eventDate;
        $endDate = $eventDate;
    } elseif ($eventDate && $eventTimestamp === false) {
        echo "    WARNING: Could not parse event date '$eventDate' with strtotime() when checking if it's in the past.\\n";
    }
    
    // Format dates properly (YYYY-MM-DD) with validation
    $startTimestamp = strtotime($startDate);
    $endTimestamp = strtotime($endDate);

    $finalStartDate = DEFAULT_FUTURE_EVENT_TICKET_START_DATE; // Safe default (Use constant)
    if ($startTimestamp === false) {
        echo "    WARNING: Invalid start date value encountered before formatting: '$startDate'. Defaulting to $finalStartDate.\\n";
    } else {
        $finalStartDate = date('Y-m-d', $startTimestamp);
    }

    $finalEndDate = DEFAULT_FUTURE_EVENT_TICKET_END_DATE_FALLBACK; // Safe default (Use constant)
    if ($endTimestamp === false) {
        // Adjust warning message source
        $endDateSourceValue = !empty($deadlineDate) ? $deadlineDate : ($eventTimestamp !== false && $eventTimestamp < time() ? $eventDate : 'Default ' . DEFAULT_FUTURE_EVENT_TICKET_END_DATE_FALLBACK); // Use constant
        echo "    WARNING: Invalid end date value encountered before formatting: '$endDate' (derived from: $endDateSourceValue). Defaulting to $finalEndDate.\\n";
    } else {
        $finalEndDate = date('Y-m-d', $endTimestamp);
    }
    
    // Update each ticket variation
    $updatedTicketVariations = [];
    foreach ($ticketVariations as $ticket) {
        // Keep all existing properties
        $updatedTicket = $ticket;
        
        // ---> START ADDITION 2: Fix specific etn_end_time values <---
        if (isset($updatedTicket['etn_end_time'])) {
            // Use constant for times to check
            if (in_array($updatedTicket['etn_end_time'], TIMES_TO_CORRECT_AM_PM)) {
                $originalTime = $updatedTicket['etn_end_time'];
                $updatedTicket['etn_end_time'] = str_replace('AM', 'PM', $originalTime);
                echo "    Corrected etn_end_time from $originalTime to {$updatedTicket['etn_end_time']}\n";
            }
        }
        // ---> END ADDITION 2 <---
        
        // Add new properties
        $updatedTicket['date_range'] = DEFAULT_TICKET_DATE_RANGE; // Use constant
        $updatedTicket['start_time'] = DEFAULT_TICKET_START_TIME; // Use constant
        // Ensure end time is set, using default if correction didn't happen and it wasn't set
        if (!isset($updatedTicket['etn_end_time'])) {
             $updatedTicket['etn_end_time'] = DEFAULT_TICKET_END_TIME; // Use constant
        }
        $updatedTicket['start_date'] = $finalStartDate; // Use validated date
        $updatedTicket['end_date'] = $finalEndDate;
        $updatedTicket['etn_enable_ticket'] = true;
        
        // Add to updated tickets array
        $updatedTicketVariations[] = $updatedTicket;
    }
    
    // Serialize the updated ticket variations
    $serializedTickets = serialize($updatedTicketVariations);
    
    // Update the database
    $updateStmt = $pdo->prepare("UPDATE {$tablePrefix}postmeta 
                                 SET meta_value = ? 
                                 WHERE meta_id = ?");
    $updateStmt->execute([$serializedTickets, $metaId]);
    
    echo "  Updated ticket variations for post ID: $postId\n";
}

echo "Migration completed successfully!\\n"; 