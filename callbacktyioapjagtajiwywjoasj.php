<?php
include_once('gay_test.php'); // Ensure this file contains your $pdo connection

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Log the received callback data
error_log("Received PayPal callback: " . print_r($data, true));

// Validate the callback data
if (!isset($data['order_id'], $data['status'], $data['amount'])) {
    http_response_code(400);
    error_log("Invalid PayPal callback data.");
    echo json_encode(['error' => 'Invalid callback data']);
    exit;
}

// Extract callback data
$orderId = $data['order_id'];
$status = $data['status'];
$amount = $data['amount'];

// Fetch the user by track_id
$stmt = $pdo->prepare("SELECT * FROM Gay WHERE track_id = ?");
$stmt->execute([$orderId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    error_log("Order ID not found in the database: $orderId");
    echo json_encode(['error' => 'Order not found']);
    exit;
}

if ($status === 'Paid') {
    // Update user's balance and payment details
    $newBalance = $user['balance'] + $amount; // Add payment amount to balance

    $stmt = $pdo->prepare("
        UPDATE Gay 
        SET balance = ?, status = 'Paid', 
            last_deposit_amount = ?, last_deposit_time = NOW() 
        WHERE track_id = ?
    ");
    $stmt->execute([$newBalance, $amount, $orderId]);

    // Notify success
    error_log("Balance updated successfully for user ID: {$user['id']}, new balance: $newBalance");
    http_response_code(200);
    echo json_encode(['success' => true]);

    // Optionally, notify the user via Telegram
    sendTelegramMessage($user['username'], "✅ Your payment of $$amount was successful! Your new balance is $$newBalance.");
} elseif ($status === 'Expired' || $status === 'Cancelled') {
    // Handle canceled or expired payments
    $stmt = $pdo->prepare("UPDATE Gay SET status = 'Expired' WHERE track_id = ?");
    $stmt->execute([$orderId]);

    // Log expiration or cancellation
    error_log("Payment expired or canceled for order ID: $orderId");
    http_response_code(200);
    echo json_encode(['success' => true]);

    // Notify the user via Telegram
    sendTelegramMessage($user['username'], "❌ Your payment of $$amount has been canceled. Please try again or contact support if you need assistance.");
} else {
    // Log unhandled status
    error_log("Unhandled payment status: $status for order ID: $orderId");
    http_response_code(400);
    echo json_encode(['error' => 'Unhandled status']);
}

// Function to send a Telegram message
function sendTelegramMessage($chatId, $message) {
    $botToken = "7754802590:AAHm52rbZz9BvOQOYxnpmgZraZwBxQskxiA"; // Replace with your bot token
    $url = "https://api.telegram.org/bot$botToken/sendMessage";

    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log("Failed to send Telegram message: " . curl_error($ch));
    }
    curl_close($ch);
}
