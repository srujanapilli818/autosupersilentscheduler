<?php

header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata'); // set to your timezone

$timeFile = __DIR__ . '/json/time_periods.json';

// Function to check if silent mode is ON              
function isSilentModeOn($timePeriods) {
    $now = strtotime(date("H:i"));
    foreach ($timePeriods as $period) {
        $start = strtotime($period['start']);
        $end   = strtotime($period['end']);
        if ($start < $end) {
            if ($now >= $start || $now <= $end) {
                return true;
            }
         else {
            if ($now >= $start && $now <= $end) {
                return true;
            }
        }
    }
    }
    return false;
}

// Load saved time periods from the JSON file
$savedPeriods = file_exists($timeFile) ? json_decode(file_get_contents($timeFile), true) : [];

// Check the silent mode status based on the loaded periods
$silentStatus = isSilentModeOn($savedPeriods);

// Prepare the response
$response = [
    'status' => 'success',
    'silent_mode_on' => $silentStatus
];

// Send the JSON response back to the frontend
echo json_encode($response);

?>