<?php

$constants = [
    "ORDER_URL" => "https://api-m.paypal.com/v2/checkout/orders",
    "ACCESS_TOKEN" => "A21AAKSPhiGqfUiSNHyNdacd_FJYgC5BXHePeXuQphMxLdrxQYPpqsuS9Ktqed3geLss14qYkGW8FJcCRvM5W4L7WGAZSyTCQ",
    "CAPTURE_URL" => "https://api-m.paypal.com/v2/checkout/orders/:order_id/capture",
    "ADMIN_TG_USERNAME" => "@ArtixKrieger",
    "TG_BOT_USERNAME" => "@ArtixKriegerTestBot"
];

define('PAYMENT_FILE', 'payment.json');

function getAuthToken() {
    if (file_exists("creds.txt")) {
        $file = file_get_contents("creds.txt");
        if ($file) {
            $data = json_decode($file, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['access_token'])) {
                return $data['access_token'];
            } else {
                logError("JSON decode error or access_token not found in creds.txt");
                return null;
            }
        } else {
            logError("Failed to read creds.txt");
            return null;
        }
    } else {
        logError("creds.txt does not exist");
        return null;
    }
}

// Function to load user data
function loadUserData()
{
    if (file_exists("data.json")) {
        $data = file_get_contents("data.json");
        return json_decode($data, true);
    }
    return [];
}

// Function to save user data
function saveUserData($data)
{
    file_put_contents("data.json", json_encode($data));
}

// Function to save payment data
function savePaymentData($data)
{
    file_put_contents(PAYMENT_FILE, json_encode($data));
}

function logError($message) {
    error_log($message . "\n", 3, 'error_log_authorize.txt');
}

if ($argc < 4) {
    logError("Insufficient arguments provided.");
    exit(1);
}


// Function to authorize order asynchronously
for ($i = 0; $i < 10; $i++) {
    
    $order_id = $argv[1];
    $user_id = $argv[2];
    $amount = $argv[3]; 
    $paymentData = ["order_id" => $order_id, "user_id" => $user_id, "amount" => $amount];  
    
    $userData = loadUserData();
    sleep(2 * 60);

     $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, "https://api-m.paypal.com/v2/checkout/orders/{$order_id}/capture");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . getAuthToken()
    ]);

    // Execute cURL session
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }

    // Close cURL session
    curl_close($ch);

    // Parse JSON response
    $json_response = json_decode($response, true);
    
    if (isset($json_response['status']) && strtolower($json_response['status']) == "completed") {
        // Write response to file
        $userData[$user_id]["balance"] += $amount;
        saveUserData($userData);
        savePaymentData($paymentData);
        logError(snprintf("Transaction completed: %s", $order_id));
        break;
    } else {
        logError("Transaction not yet authorized! : " . $order_id);
        logError(json_encode($json_response, JSON_PRETTY_PRINT));
        continue;
    }
    
}
?>