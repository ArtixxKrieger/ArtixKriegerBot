<?php
function logError($message) {
    // You can log the error to a file, the system log, or even display it
    // Example: log to a file
    $logfile = 'errors_log.txt'; // Define the file where you want to store the errors
    $timestamp = date("Y-m-d H:i:s"); // Get the current timestamp
    $logMessage = "[{$timestamp}] ERROR: {$message}\n"; // Format the log message

    // Append the error message to the log file
    file_put_contents($logfile, $logMessage, FILE_APPEND);
}

// Constants
$constants = [
    "ORDER_URL" => "https://api-m.paypal.com/v2/checkout/orders",
    "ACCESS_TOKEN" => "A21AAKSPhiGqfUiSNHyNdacd_FJYgC5BXHePeXuQphMxLdrxQYPpqsuS9Ktqed3geLss14qYkGW8FJcCRvM5W4L7WGAZSyTCQ",
    "CAPTURE_URL" => "https://api-m.paypal.com/v2/checkout/orders/:order_id/capture",
    "ADMIN_TG_USERNAME" => "",
    "TG_BOT_USERNAME" => "@ArtixKriegerTestBot"
];

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
// Function to authorize order asynchronously
function authorise_order($order_id)
{
    // Simulate asynchronous operation with sleep
    sleep(5);

    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, "https://api-m.paypal.com/v2/checkout/orders/{$order_id}/capture");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

    // Return status from JSON response
    return isset($json_response['status']) ? $json_response['status'] : 'not_processed';
}

// Function to create PayPal order
function create_order($item_name, $user_id, $price)
{
    // Check if price is valid
    if (empty($price)) {
        error_log("ERROR: Price is empty for item: $item_name and user: $user_id");
        return null; // Return null if price is missing
    }

    // Define data array for order creation
    $data = [
        "intent" => "CAPTURE",
        "payer" => [
            "payment_method" => "paypal"
        ],
        "purchase_units" => [
            [
                "reference_id" => "atrix-order-{$item_name}-{$user_id}",
                "amount" => [
                    "currency_code" => "USD",
                    "value" => $price // Ensure this is set correctly
                ]
            ]
        ], 
        "payment_source" => [
            "paypal" => [
                "experience_context" => [
                    "brand_name" => "ARTIX INC",
                    "locale" => "en-US",
                    "return_url" => "https://artixkrieger.shop/tgbot/",
                    "cancel_url" => "https://artixkrieger.shop/tgbot/cancel.html"
                ]
            ]
        ],
        "note_to_payer" => "Contact us for any issues! @" . $GLOBALS['constants']['ADMIN_TG_USERNAME'],
        "redirect_urls" => [
            "return_url" => "https://artixkrieger.shop/tgbot/",
            "cancel_url" => "https://artixkrieger.shop/tgbot/cancel.html"
        ]
    ];

    // Log the data before making the API call for better debugging
    logError("PayPal Order Data: " . json_encode($data, JSON_PRETTY_PRINT));

    try {
        // Get access token from creds.txt
        $access_token = getAuthToken();

        // Set headers for API request
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token
        ];

        // Initialize cURL session
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $GLOBALS['constants']['ORDER_URL']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute cURL session
        $response = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
            logError("ERROR: ".curl_error($ch));
            curl_close($ch);
            return null;
        }

        // Close cURL session
        curl_close($ch);

        // Parse JSON response
        $json_response = json_decode($response, true);
        
        // Log the response for debugging
        logError("PayPal API Response: " . json_encode($json_response, JSON_PRETTY_PRINT));

        if (isset($json_response['id'])) {
            // Successfully created order, now check for payer link
            $response_json['id'] = $json_response['id'];
            foreach ($json_response['links'] as $link) {
                if (strtolower($link['rel']) == 'payer-action') {
                    $response_json['link'] = $link['href'];
                }
            }
        } else {
            logError("ERROR: PayPal order creation failed. Response did not contain 'id'. Response: " . json_encode($json_response));
            return null; // Return null if the 'id' is missing
        }

        // Return JSON response
        return $response_json;

    } catch (Exception $e) {
        echo "Error occurred: " . $e->getMessage();
        logError("Exception: " . $e->getMessage());
        return null; // Return null in case of exception
    }
}


// Function to check payment status
function check_payment($order_id)
{
    // Loop for 5 attempts
    for ($i = 1; $i <= 5; $i++) {
        // Authorize order asynchronously
        $result = authorise_order($order_id);

        // Check result
        if (strtolower($result) != "completed") {
            echo "Transaction not yet authorized!\n";
            sleep(5 * 60); // Sleep for 5 minutes
        } else {
            // Write response to file
            file_put_contents("order_by_{$order_id}.txt", json_encode([], JSON_PRETTY_PRINT));
            break;
        }
    }
}

// Usage examples
// Call create_order to initiate an order
// Then call check_payment to continuously check payment status

// Example usage:
// create_order("Captain BasedCatXy", "232323", 2000);
// check_payment("your_order_id");

?>