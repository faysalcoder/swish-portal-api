<?php
// scripts/purge_helpdesk_trash.php
require __DIR__ . '/../vendor/autoload.php';

// bootstrap your app and DB connection similar to your index.php
// Example (adjust to your bootstrap):
$app = require __DIR__ . '/../app/bootstrap.php';

use App\Models\HelpdeskTicket;

$ticket = new HelpdeskTicket();
$deleted = $ticket->purgeTrashedOlderThanDays(30);
echo "Purged {$deleted} records\n";
