<?php
session_start();

// Redirect to login if the user is not authenticated
if (!isset($_SESSION['username'])) {
    header("Location: login3.html"); 
    exit;
}

// === DATABASE CONNECTION & SETUP ===
$servername = "sql100.infinityfree.com";
$db_username = "if0_40648272"; 
$db_password = "silentmode1234"; 
$dbname = "if0_40648272_silent_mode";

// Create connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// === BACKEND LOGIC START ===
date_default_timezone_set('Asia/Kolkata');
$currentUser = $_SESSION['username'];
$message = ''; // For success/error messages

// Function to load all user-defined locations from the new table
function loadUserLocations($conn, $currentUser) {
    $locations = [];
    $sql = "SELECT id, location_name, latitude, longitude, radius_m FROM user_locations WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
    $stmt->close();
    return $locations;
}

// Load all available locations for the user (for the dropdown)
$availableLocations = loadUserLocations($conn, $currentUser);
$availableLocationsMap = [];
foreach ($availableLocations as $loc) {
    $availableLocationsMap[$loc['location_name']] = $loc;
}

// --- LOGIC TO SAVE SETTINGS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromTimes = $_POST['from'] ?? [];
    $toTimes = $_POST['to'] ?? [];
    $days = $_POST['days'] ?? [];
    $selectedLocationNames = $_POST['locations'] ?? []; 
    
    // --- Get the status of the calendar access checkbox ---
    $calendarAccess = isset($_POST['calendar_access']) ? 1 : 0;
    
    // --- STEP 1: Process and Save New Custom Location Data ---
    $newLocationName = $_POST['new_location_name'] ?? null;
    $latitude = $_POST['latitude'] ?? null; 
    $longitude = $_POST['longitude'] ?? null;
    
    $is_new_location_in_db = isset($availableLocationsMap[$newLocationName]);

    $newLocationSaved = false;

    if (!empty($newLocationName) && is_numeric($latitude) && is_numeric($longitude) && !$is_new_location_in_db) {
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        
        if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
            $sql_loc_insert = "INSERT INTO user_locations (user_id, location_name, latitude, longitude) VALUES (?, ?, ?, ?)";
            $stmt_loc_insert = $conn->prepare($sql_loc_insert);
            $stmt_loc_insert->bind_param("ssdd", $currentUser, $newLocationName, $latitude, $longitude);
            $stmt_loc_insert->execute();
            $stmt_loc_insert->close();
            
            // Update the available map to include the newly saved location's coordinates for immediate validation
            $availableLocationsMap[$newLocationName] = [
                'location_name' => $newLocationName, 
                'latitude' => $latitude, 
                'longitude' => $longitude,
                'radius_m' => 100 // Default radius assumed
            ];
            $newLocationSaved = true;

            // Automatically select the newly created location
            $selectedLocationNames[] = $newLocationName;
        }
    }

    // -----------------------------------------------------------------------------------
    // --- CRITICAL STEP: VALIDATION (Prevent saving schedule without coordinates) ---
    // -----------------------------------------------------------------------------------
    $allLocationsValid = true;
    $selectedLocationNames = array_unique($selectedLocationNames);
    
    // Only perform this validation if the user actually selected locations
    if (!empty($selectedLocationNames)) {
        foreach ($selectedLocationNames as $locName) {
            // Check if coordinates exist in the available map
            if (!isset($availableLocationsMap[$locName])) {
                $allLocationsValid = false;
                break;
            }
        }
    }

    if (!$allLocationsValid) {
        // FAIL and set the required error message
        $_SESSION['message'] = "Error! The schedule was NOT saved. Enter coordinates.";
        
        header("Location: silent_mode.php");
        exit;
    }
    // -----------------------------------------------------------------------------------
    // --- END VALIDATION ---
    // -----------------------------------------------------------------------------------
    
    
    // --- STEP 2: Delete Existing Timings & Save New Schedule ---
    $isActive = 1;
    $sql_delete = "DELETE FROM silent_timings WHERE user_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("s", $currentUser);
    $stmt_delete->execute();
    $stmt_delete->close();

    // --- UPDATED: Added calendar_access_enabled to the INSERT statement ---
    $sql_insert = "INSERT INTO silent_timings (user_id, from_time, to_time, days_of_week, locations, is_active, calendar_access_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_insert = $conn->prepare($sql_insert);

    $days_json = json_encode($days);
    $locations_json = json_encode(array_values($selectedLocationNames)); 

    for ($i = 0; $i < count($fromTimes); $i++) {
        if (!empty($fromTimes[$i]) && !empty($toTimes[$i])) {
            // --- UPDATED: Added $calendarAccess to bind_param ---
            $stmt_insert->bind_param("sssssii", $currentUser, $fromTimes[$i], $toTimes[$i], $days_json, $locations_json, $isActive, $calendarAccess);
            $stmt_insert->execute();
        }
    }
    
    $stmt_insert->close();
    $_SESSION['message'] = "Settings saved successfully!";
    header("Location: silent_mode.php");
    exit;
}

// Function to check silent mode based on Time & Day only
function isSilentModeOnByTime($timePeriods, $daysOfWeek) {
    $currentDay = date("l");
    if (!is_array($daysOfWeek) || !in_array($currentDay, $daysOfWeek)) {
        return false;
    }
    
    $now = strtotime(date("H:i"));
    foreach ($timePeriods as $period) {
        $start = strtotime($period['from_time']);
        $end   = strtotime($period['to_time']);
        if ($end < $start) {
            if ($now >= $start || $now <= $end) return true;
        } else {
            if ($now >= $start && $now <= $end) return true;
        }
    }
    return false;
}

// ====================================================================================================
// === NEW LOGIC: Calculate precise time (in milliseconds) until the next scheduled state change. ===
// ====================================================================================================

/**
 * Calculates the duration (in milliseconds) until the next silent mode activation or deactivation.
 * This ensures the page reloads exactly when the status should flip.
 * * @param array $savedPeriods Array of time periods (from_time, to_time).
 * @param array $daysOfWeek Array of days (e.g., ['Monday', 'Tuesday']).
 * @param string $timezone The timezone to use (e.g., 'Asia/Kolkata').
 * @return int Milliseconds until the next event, max 300000ms (5 mins) if no event is found soon.
 */
function calculateTimeToNextEvent(array $savedPeriods, array $daysOfWeek, string $timezone = 'Asia/Kolkata'): int {
    if (empty($savedPeriods) || empty($daysOfWeek)) {
        return 60000; // Default to 60 seconds if no schedule exists
    }
    
    try {
        $now = new DateTime('now', new DateTimeZone($timezone));
        $minSecondsUntilNextEvent = PHP_INT_MAX;
        $foundEvent = false;

        $dayMap = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6
        ];
        
        $currentDayNum = (int)$now->format('w'); // 0 (Sun) to 6 (Sat)
        $selectedDaysNum = array_map(fn($d) => $dayMap[$d], $daysOfWeek);

        // Check for events up to 7 days in the future
        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $checkDayNum = ($currentDayNum + $dayOffset) % 7;
            
            if (in_array($checkDayNum, $selectedDaysNum)) {
                // Check all periods for this day
                foreach ($savedPeriods as $period) {
                    $timeStrings = [$period['from_time'], $period['to_time']];
                    
                    foreach ($timeStrings as $timeStr) {
                        // Create target DateTime object for the event
                        $target = clone $now;
                        $target->modify("+$dayOffset day");
                        
                        // Set time component, assuming $timeStr is 'H:i' format
                        list($h, $m) = explode(':', $timeStr);
                        $target->setTime((int)$h, (int)$m, 0);

                        // If the target time is in the past (on the current day, dayOffset=0), skip it
                        if ($dayOffset === 0 && $target <= $now) {
                            continue;
                        }

                        // Calculate difference (seconds)
                        $secondsUntil = $target->getTimestamp() - $now->getTimestamp();

                        // Use a small buffer (e.g., 5 seconds) to ensure the client-side reload triggers after the time has passed
                        if ($secondsUntil > 5) { 
                             $minSecondsUntilNextEvent = min($minSecondsUntilNextEvent, $secondsUntil);
                             $foundEvent = true;
                        }
                    }
                }
            }
        }
        
        // Convert the minimum duration to milliseconds
        if ($foundEvent) {
             // Cap reload time to a reasonable max (e.g., 5 minutes) to handle very distant next events
            return min($minSecondsUntilNextEvent * 1000, 300000); 
        }

    } catch (Exception $e) {
        // Log error and return default
        error_log("Error calculating next event time: " . $e->getMessage());
    }
    
    return 60000; // Default 60 seconds (1 minute) if calculation fails or no future events found
}
// ====================================================================================================
// === END NEW LOGIC ===
// ====================================================================================================

// Load saved data for the current user
$savedPeriods = [];
$days = [];
$selectedLocationNames = []; 
$selectedLocationCoords = [];
$calendarAccessEnabled = false; 

$sql_select = "SELECT from_time, to_time, days_of_week, locations, calendar_access_enabled FROM silent_timings WHERE user_id = ?";
$stmt_select = $conn->prepare($sql_select);
$stmt_select->bind_param("s", $currentUser);
$stmt_select->execute();
$result = $stmt_select->get_result();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $savedPeriods[] = $row;
        if (count($savedPeriods) === 1) {
            $days = json_decode($row['days_of_week'], true) ?? [];
            $selectedLocationNames = json_decode($row['locations'], true) ?? [];
            $calendarAccessEnabled = (bool)$row['calendar_access_enabled'];
        }
    }
}
$stmt_select->close();

if (!empty($selectedLocationNames)) {
    // Re-run location load just to get coordinate details for front-end check
    $availableLocations = loadUserLocations($conn, $currentUser); 
    $availableLocationsMap = [];
    foreach ($availableLocations as $location) {
        $availableLocationsMap[$location['location_name']] = $location;
        if (in_array($location['location_name'], $selectedLocationNames)) {
            $selectedLocationCoords[] = ['name' => $location['location_name'], 'latitude' => (float)$location['latitude'], 'longitude' => (float)$location['longitude'], 'radius' => (int)$location['radius_m']];
        }
    }
}

// === SCHEDULING STATUS LOGIC ===
$silentStatus = isSilentModeOnByTime($savedPeriods, $days);

// Set a default status message based on time/day
if (!isset($_SESSION['message']) || $_SESSION['message'] === 'Settings saved successfully!') { 
    if ($silentStatus) {
        $_SESSION['message'] = "Silent Mode ACTIVATED";
    } else {
        $_SESSION['message'] = "Silent Mode DEACTIVATED";
        
    }
}

$silentStatus = isSilentModeOnByTime($savedPeriods, $days);
if ($calendarAccessEnabled) {
    $holidays = [];
    if (file_exists('events.json')) {
        $holidays = json_decode(file_get_contents('events.json'), true);
    }
    $todayDate = date('Y-m-d');
    
    if (isset($holidays[$todayDate])) {
        $silentStatus = false; 
        // If a holiday overrides the silent status, update the message
        $_SESSION['message'] = "Silent Mode DEACTIVATED due to a detected Holiday/Event.";
    }
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// === CALCULATE NEXT RELOAD TIME HERE ===
$nextReloadTimeMS = calculateTimeToNextEvent($savedPeriods, $days);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Super Silent Scheduler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
  <link rel="icon" type="image/png" href="sm.png"> 
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', serif;
      background: #fff;
      color: #333;
    }
    header {
      width: 100%;
      background: linear-gradient(135deg,#f7aad3, #8bf8dd);
      color: #363636;
      padding: 15px 15px;
      text-align: center;
      font-size: 32px;
      font-weight: 600;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    main {
      width: 100%;
      padding: 40px;
      display: flex;
      flex-direction: column;
      gap: 30px;
    }
    .full-width-card {    
      width: 100%;
      background-color:aliceblue;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .full-width-card h4 {
      margin-bottom: 20px;
      color: #FB6F92;
    }
    .btn-primary {
      background-color: #FB6F92;
      border-color:#FB6F92;
      width: 100%;
      padding: 12px;
      font-size: 16px;
    }
    .btn-primary:hover {
      background-color:#FF8FAB;
      border-color:#FB6F92;
    }
    .btn-outline-primary {
      color: #FB6F92;
      border-color: #FB6F92;
    }
    .btn-outline-primary:hover {
      background-color: #FF8FAB;
      color: #fff;
      border-color: #ff6700;
    }
    .add-time-btn, .remove-time-btn {
      cursor: pointer;
      font-size: 24px;
      color: #FB6F92;
    }
    .form-check-input:checked {
      background-color: #FB6F92;
      border-color: #FB6F92;
    }
    #message-box { 
        position: fixed; 
        top: 20px; 
        right: 20px; 
        padding: 15px; 
        background-color: #4CAF50; 
        color: white; 
        border-radius: 5px; 
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); 
        z-index: 1000; 
        display: none; 
    }
  </style>
</head>
<body>
  <form id ="silent_mode_scheduler" action ="silent_mode.php" method = "POST">
    <input type="hidden" name="new_location_name" id="hidden_new_location_name" value="">
    <input type="hidden" name="latitude" id="hidden_latitude" value="">
    <input type="hidden" name="longitude" id="hidden_longitude" value="">
    
    <div class="container full-width-card">
      <div class="form-section">
        <header><h3 class="text-center ">SUPER SILENT SCHEDULER</h3></header>
        <div class="username-display">
            Logged in as: <?php echo htmlspecialchars($currentUser); ?>
        </div>
        
       

        <div class="form-check form-switch mb-4 d-flex flex-row justify-content-center">
          <input class="form-check-input" type="checkbox" name="is_active" id="silent-mode-toggle" <?php echo $silentStatus ? 'checked' : ''; ?>>
          <label class="form-check-label" for="silent-mode-toggle">&nbsp; Silent Mode ON/OFF</label>
        </div>

        <div class="location-suggestions">
          <h4>Locations</h4>
          <label for="location-select" class="form-label">Select Locations</label>
          <select id="location-select" name="locations[]" class="form-select" multiple>
            
            <option value="Work">Work</option>
            <option value="Gym">Gym</option>
            <option value="Cinema">Cinema</option>
            <option value="Library">Library</option>
            <option value="Meetings">Meetings</option>
            <option value="Bedtime">Bedtime</option>
            
            <?php 
            $defaultNames = ['Work', 'Gym', 'Cinema', 'Library', 'Meetings', 'Bedtime'];
            foreach ($availableLocations as $loc): 
                if (!in_array($loc['location_name'], $defaultNames)):
            ?>
              <option value="<?php echo htmlspecialchars($loc['location_name']); ?>"
                      <?php echo in_array($loc['location_name'], $selectedLocationNames) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($loc['location_name']); ?> 
              </option>
            <?php 
                endif;
            endforeach; 
            ?>
          </select>
          
          <label for="custom-location-name" class="form-label mt-3">Or add your own location (Must provide Lat/Lon)</label>
          <input type="text" id="custom-location-name" name="custom-location" class="form-control" placeholder="Enter custom location name" />
          <button type="button" class="btn btn-outline-success mt-2" onclick="addCustomLocation()">Add Custom Location & Coordinates</button>
          <button type="button" class="btn btn-info text-white mt-2" onclick="captureCurrentLocation()">Use Current GPS Location</button>
          
          <div id="gps-status" class="text-muted mt-2">
            No new GPS location pending.
          </div>
        </div>

        <div class=" mt-3">
          <label for="from_time" class="form-label"><h4>Time Periods</h4></label>

          <div id="time-container">
            <?php if (!empty($savedPeriods)): ?>
                <?php foreach ($savedPeriods as $period): ?>
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-5">
                            <input type="time" id="from_time" class="form-control" name="from[]" value="<?= htmlspecialchars($period['from_time']) ?>" placeholder="From">
                        </div>
                        <div class="col-5">
                            <input type="time" class="form-control" name="to[]" value="<?= htmlspecialchars($period['to_time']) ?>" placeholder="To">
                        </div>
                        <div class="col-auto">
                            <span class="add-time-btn" onclick="addTimeField()">&#43;</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="row g-2 align-items-center mb-2">
                    <div class="col-5">
                        <input type="time" class="form-control" name="from[]" placeholder="From">
                    </div>
                    <div class="col-5">
                        <input type="time" class="form-control" name="to[]" placeholder="To">
                    </div>
                    <div class="col-auto">
                        <span class="add-time-btn" onclick="addTimeField()">&#43;</span>
                    </div>
                </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-3">
          <label for="days" class="form-label"><h4>Days of the Week</h4></label>
          <select id="days" class="form-select" name="days[]" multiple>
            <option value="Sunday">Sunday</option>
            <option value="Monday">Monday</option>
            <option value="Tuesday">Tuesday</option>
            <option value="Wednesday">Wednesday</option>
            <option value="Thursday">Thursday</option>
            <option value="Friday">Friday</option>
            <option value="Saturday">Saturday</option>
          </select>
        </div>

        <div class="form-check form-switch mt-3 mb-4">
          <input class="form-check-input" type="checkbox" id="calendarAccess" name="calendar_access" <?php echo $calendarAccessEnabled ? 'checked' : ''; ?>>
          <label class="form-check-label" for="calendarAccess">Calendar Access (Disable silent mode on holidays)</label>
        </div>
        <button class="btn btn-primary w-100" type="submit">Save Settings</button>
      </div>
    </div>
    <div id="message-box" role="alert"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    <script>
        // JAVASCRIPT
        // Register service worker
         // Pass PHP data to JavaScript
        const initialSelectedDays = <?php echo json_encode($days); ?>;
        const initialSelectedLocationNames = <?php echo json_encode($selectedLocationNames); ?>;
        const availableLocationsMap = <?php echo json_encode($availableLocationsMap); ?>;
        const selectedLocationCoords = <?php echo json_encode(array_values($selectedLocationCoords)); ?>;
        
        // === NEW VARIABLE: Precise calculated time until next reload ===
        const nextReloadTimeMS = <?php echo $nextReloadTimeMS; ?>; 
        
        const DEFAULT_LOCATIONS = ['Work', 'Gym', 'Cinema', 'Library', 'Meetings', 'Bedtime'];

        // --- HELPER FUNCTIONS ---
        function clearNewLocationFields() {
            document.getElementById('hidden_new_location_name').value = '';
            document.getElementById('hidden_latitude').value = '';
            document.getElementById('hidden_longitude').value = '';
            document.getElementById('gps-status').innerHTML = 'No new GPS location pending.';
            document.getElementById('custom-location-name').value = '';
        }

        function showMessage(message, type) {
            const msgBox = document.getElementById('message-box');
            
            // Set alert style based on content/type
            // MODIFIED LOGIC: Check for "Settings saved successfully!" specifically
            if (message.includes("Settings saved successfully!")) {
                 msgBox.style.backgroundColor = "#4CAF50"; // Green for success
            } else if (message.includes("Error! The schedule was NOT saved.") || message.includes("**OFF**") || type === "error") {
                msgBox.style.backgroundColor = "#f44336"; // Red for error/off status
            } else if (message.includes("**ON**") || type === "success") {
                msgBox.style.backgroundColor = "#4CAF50"; // Green for success/on status
            } else {
                 msgBox.style.backgroundColor = "#2196F3"; // Blue for info/other
            }

            msgBox.textContent = message.replace(/\*\*/g, ''); // Remove markdown for display
            msgBox.style.display = "block";
            setTimeout(() => { msgBox.style.display = "none"; }, 4000);
        }
        
        function promptAndSetLocationCoordinates(locationName) {
            clearNewLocationFields();
            
            const newLat = prompt(`Enter Latitude for "${locationName}":`);
            if (newLat === null || isNaN(newLat)) { showMessage("Latitude required and must be a number.", "error"); return false; }
            const newLon = prompt(`Enter Longitude for "${locationName}":`);
            if (newLon === null || isNaN(newLon)) { showMessage("Longitude required and must be a number.", "error"); return false; }
            
            document.getElementById('hidden_new_location_name').value = locationName;
            document.getElementById('hidden_latitude').value = newLat;
            document.getElementById('hidden_longitude').value = newLon;
            
            document.getElementById('gps-status').innerHTML = `**New Location Ready to Save:** ${locationName} (Lat ${parseFloat(newLat).toFixed(4)}, Lon ${parseFloat(newLon).toFixed(4)}). <br>Click 'Save Settings' to permanently add and select this location.`;
            showMessage(`Coordinates recorded for "${locationName}". Click 'Save Settings'.`, "success");
            return true;
        }

        // --- CHOICES.JS INITIALIZATION ---

        const locationSelector = new Choices('#location-select', { 
            removeItemButton: true, 
            placeholder: true, 
            placeholderValue: 'Select locations',
        });
        
        const daySelector = new Choices('#days', { removeItemButton: true, placeholder: true, placeholderValue: 'Select days' });
        daySelector.setChoiceByValue(initialSelectedDays);

        locationSelector.setChoiceByValue(initialSelectedLocationNames);

        // --- FINAL LISTENER FIX: TRIGGER PROMPT ON ADD ITEM ---
        locationSelector.passedElement.element.addEventListener('addItem', function(event) {
            const selectedValue = event.detail.value;
            const isDefault = DEFAULT_LOCATIONS.includes(selectedValue);
            
            // Unconditionally prompt for ALL selected locations that are either default or a new, unsaved custom entry
            if (isDefault || !availableLocationsMap[selectedValue]) {
                const success = promptAndSetLocationCoordinates(selectedValue);
                
                if (!success) {
                    // If the user cancels the prompt, deselect the location
                    locationSelector.removeActiveItemByValue(selectedValue);
                    showMessage(`Selection removed for "${selectedValue}". Coordinates are required for scheduling.`, "error");
                }
            } else if (availableLocationsMap[selectedValue]) {
                 // Custom locations that already exist and have coordinates (inform user, don't prompt)
                 clearNewLocationFields(); // Still clear any dangling fields
                 showMessage(`Location "${selectedValue}" selected. Coordinates were already saved.`, "success");
            }
        });
        // --- END FINAL LISTENER FIX ---
        
        // --- CUSTOM LOCATION ADDITION LOGIC ---

        function addCustomLocation() {
            const customLocationInput = document.getElementById("custom-location-name");
            const customLocationName = customLocationInput.value.trim();

            if (!customLocationName) { 
                showMessage("Please enter a valid location name first.", "error"); 
                return; 
            }
            
            clearNewLocationFields();

            const currentlySelected = locationSelector.getValue(true);
            if (currentlySelected.includes(customLocationName)) {
                showMessage(`Location "${customLocationName}" is already selected.`, "error");
                customLocationInput.value = '';
                return;
            }

            const needsCoordinates = !availableLocationsMap[customLocationName];

            if (needsCoordinates) {
                if (promptAndSetLocationCoordinates(customLocationName)) { 
                    // Add the new location as a new option to Choices.js and select it
                    locationSelector.setChoiceByValue(currentlySelected.concat(customLocationName));
                    customLocationInput.value = '';
                }
            } else {
                // Location already has coordinates saved. Just select it.
                locationSelector.setChoiceByValue(currentlySelected.concat(customLocationName));
                customLocationInput.value = '';
                showMessage(`Location "${customLocationName}" selected. Coordinates were already saved.`, "success");
            }
        }
        
        // Function to capture and display current GPS coordinates
        function captureCurrentLocation() {
            // Do NOT read from custom-location-name, but use a default label for the hidden field
            const locationName = "Current GPS Capture"; // Use a fixed internal name if custom input is empty
            
            clearNewLocationFields();

            if (navigator.geolocation) {
                showMessage("Attempting to get your current GPS location...", "success");
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const latitude = position.coords.latitude;
                        const longitude = position.coords.longitude;
                        
                        // Check if the user had entered a name before clicking the button
                        const actualLocationName = document.getElementById('custom-location-name').value.trim() || locationName;

                        document.getElementById('hidden_new_location_name').value = actualLocationName;
                        document.getElementById('hidden_latitude').value = latitude;
                        document.getElementById('hidden_longitude').value = longitude;

                        document.getElementById('gps-status').innerHTML = `**New Location Ready to Save:** ${actualLocationName} (Lat ${parseFloat(latitude).toFixed(4)}, Lon ${parseFloat(longitude).toFixed(4)}). <br>Click 'Save Settings' to permanently add and select this location.`;
                        
                        showMessage(`✅ Success! Current GPS recorded. Click 'Save Settings' to save it as "${actualLocationName}".`, "success");
                        
                        // REMOVED LINE: document.getElementById('custom-location-name').value = actualLocationName;
                        // This prevents the text field from being populated.
                    },
                    (error) => { 
                        let errorMessage = "Error: GPS access failed. Check permissions.";
                        if (error.code === 1) {
                            errorMessage = "Error: Geolocation permission denied by user.";
                        }
                        showMessage(errorMessage, "error"); 
                    },
                    { enableHighAccuracy: false, timeout: 7000, maximumAge: 0 }
                );
            } else { 
                showMessage("Geolocation is not supported by this browser.", "error"); 
            }
        }
        
        // --- TIME/DAY/STATUS FUNCTIONS (UNCHANGED) ---
        
        function addTimeField() {
            const timeContainer = document.getElementById('time-container');
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2';
            row.innerHTML = `<div class="col-5"><input type="time" class="form-control" name="from[]" placeholder="From"></div><div class="col-5"><input type="time" class="form-control" name="to[]" placeholder="To"></div><div class="col-auto"><span class="remove-time-btn" onclick="removeTimeField(this)">&minus;</span><span class="add-time-btn" onclick="addTimeField()">&#43;</span></div>`;
            timeContainer.appendChild(row);
        }
        function removeTimeField(element) {
            const parentDiv = element.closest('.row');
            if (document.getElementById('time-container').children.length > 1) {
                parentDiv.remove();
            } else {
                showMessage("Cannot remove the last time period.", "error");
            }
        }
        
        // Distance check logic 
        function getDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000;
            const toRad = (value) => (value * Math.PI) / 180;
            const dLat = toRad(lat2 - lat1);
            const dLon = toRad(lon2 - lon1);
            const x = dLon * Math.cos((toRad(lat1) + toRad(lat2)) / 2);
            const distance = R * Math.sqrt(x * x + dLat * dLat);
            return distance;
        }
        
        function isNearAnySavedLocation(currentLat, currentLon, locationsArray) {
            if (locationsArray.length === 0) return false;
            for (const loc of locationsArray) {
                const distance = getDistance(currentLat, currentLon, loc.latitude, loc.longitude);
                const RADIUS_M = loc.radius || 100;
                if (distance <= RADIUS_M) { console.log(`Location Match Found: ${loc.name}`); return true; }
            }
            return false;
        }
        
        function checkLocationStatus() {
            if (selectedLocationCoords.length === 0) return;
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const currentLat = position.coords.latitude;
                        const currentLon = position.coords.longitude;

                        if (isNearAnySavedLocation(currentLat, currentLon, selectedLocationCoords)) {
                            // Location Match! Set ON and alert user
                            document.getElementById('silent-mode-toggle').checked = true;
                            showMessage("Silent Mode ACTIVATED", "success");
                            showSystemNotification("Silent Mode On", "You entered a silent zone.");
                        } else {
                            // No Location Match. If the time/day setting is also OFF, show a single OFF alert.
                            if (!document.getElementById('silent-mode-toggle').checked) { 
                                showMessage("Silent Mode DEACTIVATED.", "error"); 
                                 showSystemNotification("Silent Mode Off", "You left the silent zone.");
                            }
                        }
                    },
                    (error) => { console.warn(`Error getting location for check: ${error.message}`); },
                    { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
                );
            }
        }
        
        checkLocationStatus();
        
        // === Auto-Reload Logic (UPDATED) ===
        /**
         * Reloads the page using the precise duration calculated by PHP.
         */
        function autoReloadPage() {
            // nextReloadTimeMS is passed from PHP, giving the exact milliseconds until the next scheduled flip.
            console.log("Setting up auto-reload for precise time check in: " + (nextReloadTimeMS / 1000).toFixed(2) + " seconds.");
            
            setTimeout(() => {
                window.location.reload();
            }, nextReloadTimeMS); 
        }

        // Start the automatic reload on script execution
        autoReloadPage();
        
        // --- Display Server Message as an Alert AND System Notification ---
        <?php
        // Get the PHP message and sanitize it once for JavaScript
        $msg_js = htmlspecialchars($message);

        // Check the content of the message to decide which notification to show
        if ($message === "Silent Mode ACTIVATED"):
        ?>
            // 1. Show the on-page alert
            showMessage("<?php echo $msg_js; ?>", "success");
            
            // 2. NEW: Show the system notification for TIME-BASED activation
            showSystemNotification("Silent Mode On", "Silent mode has been activated by your schedule.");

        <?php 
        // Check for both time-based deactivation OR holiday deactivation
        elseif ($message === "Silent Mode DEACTIVATED" || $message === "Silent Mode DEACTIVATED due to a detected Holiday/Event."): 
        ?>
            // 1. Show the on-page alert
            showMessage("<?php echo $msg_js; ?>", "error");

            // 2. NEW: Show the system notification for TIME-BASED deactivation
            showSystemNotification("Silent Mode Off", "Silent mode has been deactivated by your schedule.");

        <?php 
        // For all other messages (e.g., "Settings saved", "Error!...")
        else: 
        ?>
            // 1. Just show the on-page alert
            showMessage("<?php echo $msg_js; ?>", "error");
        <?php endif; ?>

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js')
    .then(reg => console.log('Service Worker registered:', reg))
    .catch(err => console.error('Service Worker registration failed:', err));
}

// Request notification permission when the page loads
document.addEventListener('DOMContentLoaded', () => {
  if (Notification.permission !== 'granted') {
    Notification.requestPermission().then(permission => {
      console.log('Notification permission:', permission);
    });
  }
});

// Example functions to simulate silent mode activation/deactivation
// Function to send system notifications using the service worker
function showSystemNotification(title, body) { // Renamed for clarity and to match your previous call
  if (Notification.permission === 'granted') {
    navigator.serviceWorker.getRegistration().then(reg => {
      if (reg) {
        reg.showNotification(title, {
          body: body,
          icon: 'sm.png',
          tag: 'silent-mode-status', // Optional: Use a tag to prevent multiple notifications of the same type
          renotify: true // Optional: Resend notification if a new one with the same tag arrives
        });
      }
    });
  }
}

   </script>
  </form>
</body>
</html>