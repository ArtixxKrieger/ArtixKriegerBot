<?php
$botToken = "7754802590:AAHm52rbZz9BvOQOYxnpmgZraZwBxQskxiA";
define('botToken', '7754802590:AAHm52rbZz9BvOQOYxnpmgZraZwBxQskxiA');
define("API_URL", "https://api.telegram.org/bot" . botToken . "/");
$apiUrl = "https://api.telegram.org/bot$botToken/";
$rolesFile = "data.json";
$stateFile = "state.json";
require_once 'gay_test.php';

function getBotToken($pdo) {
    if (!$pdo) {
        throw new Exception("Database connection not established.");
    }

    $sql = "SELECT config_value FROM BotConfig WHERE config_key = 'bot_token'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        return $result['config_value'];
    } else {
        throw new Exception("Bot token not found in the database.");
    }
}

function deleteMessage($chatId, $messageId) {
    global $botToken;

    $deleteUrl = "https://api.telegram.org/bot" . $botToken . "/deleteMessage";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];

    // Use cURL to send the delete request
    $ch = curl_init($deleteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);
    curl_close($ch);

    error_log("Delete message response: " . $response); // Log the response for debugging
}

// Load and save JSON data
function loadJson($file) {
    return json_decode(file_get_contents($file), true);
}

function saveJson($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log("JSON encoding error: " . json_last_error_msg());
        return false;
    }

    $result = file_put_contents($file, $json);

    if ($result === false) {
        error_log("Failed to write to file: $file. Check file permissions or disk space.");
        return false;
    }

    error_log("Data successfully saved to $file.");
    return true;
}

// Send a message

// Send a message
function sendMessage($chatId, $text, $keyboard = null) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => $keyboard ? json_encode($keyboard) : null,
        'parse_mode' => 'HTML'
    ];

    // Use cURL for safer and more robust HTTP requests
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . "sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // Log the response for debugging
    if ($response === false) {
        error_log("Failed to send message: " . curl_error($ch));
    } else {
        error_log("Telegram API response: " . $response);
    }
}


// Send a file
function sendFile($chatId, $filePath, $caption = null) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'document' => new CURLFile(realpath($filePath)),
        'caption' => $caption
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . "sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec($ch);
    curl_close($ch);
}

//view users
function handleViewUsers($chatId) {
    global $pdo;

    try {
        $sql = "SELECT id, username, role, balance FROM Gay WHERE role IN ('user', 'resellers')";
        $stmt = $pdo->query($sql);
        $usersAndResellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$usersAndResellers) {
            sendMessage($chatId, "❌ Debug: No users or resellers found in the database.");
            return;
        }

        // Step 2: Filter entries with zero balance
        $filteredEntries = array_filter($usersAndResellers, function ($entry) {
            return $entry['balance'] > 0;
        });

        if (empty($filteredEntries)) {
            sendMessage($chatId, "❌ Debug: No entries with balance greater than 0.");
            return;
        }

        // Step 3: Separate users and resellers
        $users = array_filter($filteredEntries, function ($entry) {
            return $entry['role'] === 'user';
        });

        $resellers = array_filter($filteredEntries, function ($entry) {
            return $entry['role'] === 'resellers';
        });


        // Step 4: Sort all entries by balance in descending order
        $allEntries = array_merge($users, $resellers);
        usort($allEntries, function ($a, $b) {
            return $b['balance'] <=> $a['balance'];
        });

        // Step 5: Build the combined message
        $message = "📋 <b> List of Users and Resellers Balance</b>\n\n";

        if (!empty($users)) {
            $message .= "👤 <b>Users</b>\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= sprintf("%-15s %-10s\n", "Telegram ID", "Balance");
            $message .= "━━━━━━━━━━━━━━━━━━━━━━━\n";

            foreach ($users as $user) {
                $message .= sprintf("%-15s %-10s\n", $user['id'], $user['balance']);
            }
            $message .= "\n";
        } else {
            $message .= "❌ Debug: No users with balance.\n";
        }

        if (!empty($resellers)) {
            $message .= "🤝 <b>Resellers</b>\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= sprintf("%-15s %-10s\n", "Telegram ID", "Balance");
            $message .= "━━━━━━━━━━━━━━━━━━━━━━━\n";

            foreach ($resellers as $reseller) {
                $message .= sprintf("%-15s %-10s\n", $reseller['id'], $reseller['balance']);
            }
            $message .= "\n";
        } else {
            $message .= "❌ Debug: No resellers with balance.\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━━━━";

        // Step 6: Send the combined message
        sendMessage($chatId, $message);

        // Step 7: Create a CSV file for all users and resellers
        $filePath = 'users_and_resellers_data.csv';
        $file = fopen($filePath, 'w');

        // Add header row
        fputcsv($file, ['ID', 'Username', 'Role', 'Balance']);

        // Write all entries to the CSV file
        foreach ($allEntries as $entry) {
            fputcsv($file, [$entry['id'], $entry['username'], $entry['role'], $entry['balance']]);
        }

        fclose($file);

        // Step 8: Send the file to the user
        sendFile($chatId, $filePath, "📂 Here is the combined data for users and resellers in the database.");

        // Step 9: Delete the temporary file after sending
        unlink($filePath);
    } catch (Exception $e) {
        // Catch any unexpected errors and send a debug message
        sendMessage($chatId, "❌ Debug: An error occurred - " . $e->getMessage());
    }
}




function sendAlert($callbackQueryId, $text) {
    $alertData = [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => true
    ];

    $ch = curl_init("https://api.telegram.org/bot" . botToken . "/answerCallbackQuery");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $alertData);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

function getUser($chatId) {
    global $pdo;
    $sql = "SELECT * FROM Gay WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $chatId]);
    return $stmt->fetch(PDO::FETCH_ASSOC); // Returns user data as an associative array
}

// Check if user is an admin
function isAdmin($chatId) {
    global $pdo;
    $user = getUser($chatId);
    return $user && $user['role'] === 'admin'; // Strictly check for admin role
}

function isReseller($chatId) {
    global $pdo;
    $user = getUser($chatId);
    return $user && $user['role'] === 'resellers'; // Strictly check for resellers role
}

function sendVideo($chatId, $videoUrl, $caption = '', $keyboard = null) {
    global $botToken; // Ensure the bot token is available globally

    $data = [
        'chat_id' => $chatId,
        'video' => $videoUrl, // Must be a direct video file URL
        'caption' => $caption,
        'reply_markup' => $keyboard ? json_encode($keyboard) : null, // Encode keyboard if provided
        'parse_mode' => 'HTML'
    ];

    // Initialize cURL for API request
    $ch = curl_init("https://api.telegram.org/bot" . $botToken . "/sendVideo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);

    if ($response === false) {
        error_log("cURL Error: " . curl_error($ch)); // Log cURL error
    } else {
        error_log("Telegram API Response: " . $response); // Log Telegram response
    }

    curl_close($ch);
    return $response; // Return the API response for debugging
}

function checkChannelMembership($chatId, $userId, $channelId) {
    $data = [
        'chat_id' => $channelId, // Use the username here (e.g., @ArtixKriegerCH)
        'user_id' => $userId
    ];

    // Use cURL for API call
    $ch = curl_init("https://api.telegram.org/bot" . botToken . "/getChatMember");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($response, true);

    // Debugging: Write response to log file
    file_put_contents('debug_log.txt', json_encode($response) . PHP_EOL, FILE_APPEND);

    if ($response['ok']) {
        $status = $response['result']['status'];
        return in_array($status, ['member', 'administrator', 'creator']);
    }

    // Handle errors
    if (isset($response['error_code'])) {
        file_put_contents('error_log.txt', "Error {$response['error_code']}: {$response['description']}" . PHP_EOL, FILE_APPEND);
    }

    return false;
}


function handleStartCommand($chatId) {
    // Display the join channel menu
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "✅ I've Joined the Channel", 'callback_data' => 'joined']]
        ]
    ];
    sendVideo($chatId, "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4", "🚀𝐖𝐞𝐥𝐜𝐨𝐦𝐞 𝐭𝐨 𝐀𝐫𝐭𝐢𝐱𝐊𝐫𝐢𝐞𝐠𝐞𝐫 𝐁𝐨𝐭🤖\n\n𝘞𝘦 𝘰𝘧𝘧𝘦𝘳 𝘢 𝘳𝘢𝘯𝘨𝘦 𝘰𝘧 𝘬𝘦𝘺𝘴 𝘧𝘰𝘳 𝘱𝘶𝘳𝘤𝘩𝘢𝘴𝘦. 𝘗𝘭𝘦𝘢𝘴𝘦 𝘯𝘰𝘵𝘦 𝘵𝘩𝘢𝘵 𝘰𝘶𝘳 𝙆𝙀𝙔𝙎 𝘼𝙍𝙀 𝙉𝙊𝙏 𝙁𝙍𝙀𝙀‼️ 𝘠𝘰𝘶 𝘤𝘢𝘯 𝘤𝘰𝘯𝘷𝘦𝘯𝘪𝘦𝘯𝘵𝘭𝘺 𝘮𝘢𝘬𝘦 𝘺𝘰𝘶𝘳 𝘱𝘶𝘳𝘤𝘩𝘢𝘴𝘦 𝘩𝘦𝘳𝘦 𝘪𝘯 𝘵𝘩𝘦 𝘣𝘰𝘵. 𝘚𝘪𝘮𝘱𝘭𝘺 𝘭𝘦𝘵 𝘶𝘴 𝘬𝘯𝘰𝘸 𝘸𝘩𝘢𝘵 𝘺𝘰𝘶 𝘯𝘦𝘦𝘥, 𝘢𝘯𝘥 𝘸𝘦'𝘭𝘭 𝘢𝘴𝘴𝘪𝘴𝘵 𝘺𝘰𝘶 𝘸𝘪𝘵𝘩 𝘺𝘰𝘶𝘳 𝘱𝘶𝘳𝘤𝘩𝘢𝘴𝘦.\n\n𝘛𝘩𝘢𝘯𝘬 𝘺𝘰𝘶 𝘧𝘰𝘳 𝘤𝘩𝘰𝘰𝘴𝘪𝘯𝘨 𝘈𝘳𝘵𝘪𝘹𝘒𝘳𝘪𝘦𝘨𝘦𝘳 𝘉𝘰𝘵!\n\n<b>🔰 Join Channel: @ArtixKriegerCH</b>"
,$keyboard);
}


// Show main menu
function showUserMenu($chatId) {
    $isAdmin = isAdmin($chatId);
    $isReseller = isReseller($chatId);

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "💵 Balance", 'callback_data' => 'balance_menu'],
                ['text' => "🏆 Easy Victory", 'callback_data' => 'hack_menu']
            ],
        ],
    ];

    if ($isAdmin) {
        $keyboard['inline_keyboard'][] = [['text' => "⚙️ Admin Panel", 'callback_data' => 'admin_menu']];
    }

    if ($isReseller) {
        $keyboard['inline_keyboard'][] = [['text' => "📦 Resellers Setting", 'callback_data' => 'reseller_menu']];
    }

    sendVideo($chatId, 
        "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4", 
        "🚀𝐖𝐞𝐥𝐜𝐨𝐦𝐞 𝐭𝐨 𝐀𝐫𝐭𝐢𝐱𝐊𝐫𝐢𝐞𝐠𝐞𝐫 𝐁𝐨𝐭🤖\n\n𝘠𝘰𝘶 𝘤𝘢𝘯 𝘤𝘰𝘯𝘷𝘦𝘯𝘪𝘦𝘯𝘵𝘭𝘺 𝘮𝘢𝘬𝘦 𝘺𝘰𝘶𝘳 𝘱𝘶𝘳𝘤𝘩𝘢𝘴𝘦 𝘩𝘦𝘳𝘦 𝘪𝘯 𝘵𝘩𝘦 𝘣𝘰𝘵. 𝘚𝘪𝘮𝘱𝘭𝘺 𝘭𝘦𝘵 𝘶𝘴 𝘬𝘯𝘰𝘸 𝘸𝘩𝘢𝘵 𝘺𝘰𝘶 𝘯𝘦𝘦𝘥, 𝘢𝘯𝘥 𝘸𝘦'𝘭𝘭 𝘢𝘴𝘴𝘪𝘴𝘵 𝘺𝘰𝘶 𝘸𝘪𝘵𝘩 𝘺𝘰𝘶𝘳 𝘱𝘶𝘳𝘤𝘩𝘢𝘴𝘦.\n\n𝘛𝘩𝘢𝘯𝘬 𝘺𝘰𝘶 𝘧𝘰𝘳 𝘤𝘩𝘰𝘰𝘴𝘪𝘯𝘨 𝘈𝘳𝘵𝘪𝘹𝘒𝘳𝘪𝘦𝘨𝘦𝘳 𝘉𝘰𝘵!\n\n<b>🔰 Join Channel: @ArtixKriegerCH</b>", 
        $keyboard);
}


/////////////RESELERS SHIT
function getResellerData($chatId) {
    global $pdo;

    // Fetch the user data from the database
    $query = $pdo->prepare("SELECT username, role FROM Gay WHERE role = 'resellers' AND username = ?");
    $user = getUser($chatId); // Assuming getUser fetches the Telegram user details
    $query->execute([$user['username']]);
    $reseller = $query->fetch(PDO::FETCH_ASSOC);

    if ($reseller) {
        return [
            'id' => $reseller['username'], // Use the Telegram username as the id
            'name' => $reseller['username'] // Use the database 'username' column for the name
        ];
    }

    error_log("[ERROR] Reseller not found for chatId: $chatId.");
    return null;
}


function handleResellerMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "📸 Upload Avatar", 'callback_data' => 'add_avatar'],
                ['text' => "🌐 Social Media", 'callback_data' => 'add_social_links'],
            ],
            [
                ['text' => "💵 Add Payment", 'callback_data' => 'add_description'],
                ['text' => "👤 Show Profile", 'callback_data' => 'show_profile'],
            ],
            [
                ['text' => "🔙 Back", 'callback_data' => 'main_menu'], // Back button at the bottom
            ]
        ]
    ];

   sendVideo($chatId, 
        "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4", 
        "<b>🌟 Welcome to Reseller Dashboard! 🌟</b>\n\n" .
        "<i>✨ Customize Your Profile:</i>\n\n" .
        "Choose one of the options below to enhance your reseller experience:\n\n" .
        "1️⃣ <b>📸 Upload Avatar</b>: Personalize your profile with a unique avatar!\n\n" .
        "2️⃣ <b>🌐 Social Media</b>: Link your social media accounts to connect with your audience.\n\n" .
        "3️⃣ <b>💵 Add Payment</b>: Set up your payment methods for smooth transactions.\n\n" .
        "4️⃣ <b>👤 Show Profile</b>: View your current profile settings and details.\n\n" .
        "<i>🔙 Need to go back?</i> Just hit the 'Back' button below!", 
        $keyboard);
}

function sendPhoto($chatId, $photoPath, $caption) {
    global $botToken;

    $url = "https://api.telegram.org/bot$botToken/sendPhoto";
    $postFields = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($photoPath),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $response = curl_exec($ch);
    curl_close($ch);

    error_log("[DEBUG] Sent photo to chatId: $chatId. Response: $response");
}
function handleShowProfile($chatId) {
    global $pdo;

    // Fetch the username from the database
    $stmt = $pdo->prepare("SELECT username FROM Gay WHERE id = ?");
    $stmt->execute([$chatId]);
    $username = $stmt->fetchColumn();

    if (!$username) {
        sendMessage($chatId, "⚠️ Username not found for your ID.");
        error_log("[ERROR] Username not found for chatId: $chatId.");
        return;
    }

    // Remove '@' from username
    $username = ltrim($username, '@');

    // Locate the user's JSON file
    $userFilePath = getUserFilePath($username);

    if (!$userFilePath) {
        sendMessage($chatId, "⚠️ Profile data not found.");
        error_log("[ERROR] Profile data file not found for username: $username.");
        return;
    }

    // Load JSON data
    $data = json_decode(file_get_contents($userFilePath), true);

    // Validate the data structure
    if (!is_array($data) || empty($data) || !isset($data['id'])) {
        sendMessage($chatId, "⚠️ Your profile data is empty or invalid.");
        error_log("[ERROR] Invalid or empty profile data in $userFilePath.");
        return;
    }

    // Decode the avatar from Base64
    $avatarPath = null;
    if (!empty($data['avatar'])) {
        $avatarPath = __DIR__ . "/temp_avatar_$chatId.jpg";
        $decodedAvatar = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $data['avatar']));
        if ($decodedAvatar !== false) {
            file_put_contents($avatarPath, $decodedAvatar);
        } else {
            error_log("[ERROR] Failed to decode avatar for username: $username.");
            $avatarPath = null;
        }
    }

    // Build the profile message
    $profileMessage = "👤 <b>Reseller Profile</b>\n";
    $profileMessage .= "━━━━━━━━━━━━━━━━━━━━━\n";
    $profileMessage .= "🆔 <b>ID:</b> <code>" . htmlspecialchars($data['id']) . "</code>\n";
    $profileMessage .= "📛 <b>Name:</b> <i>" . htmlspecialchars($data['name']) . "</i>\n";
    $profileMessage .= "━━━━━━━━━━━━━━━━━━━━━\n\n";

    if (!empty($data['paymentMethods'])) {
        $profileMessage .= "💳 <b>Payment Methods</b>\n";
        $profileMessage .= "━━━━━━━━━━━━━━━━━━━━━\n";
        foreach ($data['paymentMethods'] as $method) {
            $profileMessage .= "▫️ " . htmlspecialchars($method) . "\n";
        }
        $profileMessage .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
    }

    if (!empty($data['contacts'])) {
        $profileMessage .= "🔗 <b>Social Media Links</b>\n";
        $profileMessage .= "━━━━━━━━━━━━━━━━━━━━━\n";
        foreach ($data['contacts'] as $contact) {
            $profileMessage .= "▫️ <b>" . ucfirst(htmlspecialchars($contact['type'])) . ":</b> <a href=\"" . htmlspecialchars($contact['url']) . "\">" . htmlspecialchars($contact['url']) . "</a>\n";
        }
        $profileMessage .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
    }

    $profileMessage .= "✨ <i>Thank you for being a part of our reseller community!</i> ✨";

    // Add Reset and Start buttons
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "❌ Reset Data", 'callback_data' => 'reset_data'],
                ['text' => "🔄 Upload Data", 'callback_data' => 'start']
            ]
        ]
    ];

    // Send the avatar (if available) and the profile information
    if ($avatarPath) {
        sendPhotoWithKeyboard($chatId, $avatarPath, $profileMessage, $keyboard);
        unlink($avatarPath); // Clean up the temporary file
    } else {
        sendMessageWithKeyboard($chatId, $profileMessage, $keyboard);
    }
}


function sendPhotoWithKeyboard($chatId, $photoPath, $caption, $keyboard) {
    global $botToken;

    $url = "https://api.telegram.org/bot$botToken/sendPhoto";
    $postFields = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($photoPath),
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("[DEBUG] Sent photo to chatId: $chatId. HTTP Code: $httpCode. Response: $response");
}


function sendMessageWithKeyboard($chatId, $text, $keyboard) {
    global $botToken;

    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $postFields = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("[DEBUG] Sent message to chatId: $chatId. HTTP Code: $httpCode. Response: $response");
}

function handleResetData($chatId) {
    global $pdo;

    // Fetch the username from the database
    $stmt = $pdo->prepare("SELECT username FROM Gay WHERE id = ?");
    $stmt->execute([$chatId]);
    $username = $stmt->fetchColumn();

    if (!$username) {
        sendMessage($chatId, "⚠️ Username not found for your ID.");
        error_log("[ERROR] Username not found for chatId: $chatId.");
        return;
    }

    // Remove @ from username
    $username = ltrim($username, '@');

    // Locate the user's JSON file
    $userFilePath = getUserFilePath($username);

    if (!$userFilePath) {
        sendMessage($chatId, "⚠️ No data found to reset.");
        error_log("[ERROR] Resellers data file not found for username: $username.");
        return;
    }

    // Load JSON data
    $data = json_decode(file_get_contents($userFilePath), true);

    // Validate the data structure
    if (!is_array($data) || empty($data) || !isset($data['id'])) {
        sendMessage($chatId, "⚠️ No matching data found to reset.");
        error_log("[ERROR] Invalid or empty data in $userFilePath.");
        return;
    }

    // Reset the user's data while retaining ID and name
    $data = [
        "id" => $data['id'], // Retain the ID
        "name" => $data['name'], // Retain the name
        "avatar" => null,
        "contacts" => [],
        "paymentMethods" => [],
        "tags" => []
    ];

    // Save the cleared data
    if (file_put_contents($userFilePath, json_encode($data, JSON_PRETTY_PRINT))) {
        sendMessage($chatId, "✅ Your data has been reset successfully." );
        error_log("[SUCCESS] Data reset for username: $username.");
                autoCheckAndUpdateRoles();
    } else {
        sendMessage($chatId, "⚠️ Failed to reset your data. Please try again.");
        error_log("[ERROR] Failed to save cleared data for username: $username.");
                autoCheckAndUpdateRoles();
    }
}


function handleStart($chatId) {
    global $botToken;
    sendMessage($chatId, "👋 Data Successfully Uploaded To App.");
    handleResellerMenu($chatId); // Return to the reseller menu
    sendResellerData($botToken);

}


function downloadFile($fileId, $callback) {
    global $botToken;

    $fileUrl = "https://api.telegram.org/bot$botToken/getFile?file_id=$fileId";
    error_log("[DEBUG] Fetching file path from Telegram API for fileId: $fileId");

    $response = file_get_contents($fileUrl);
    $fileData = json_decode($response, true);

    if ($fileData['ok'] && isset($fileData['result']['file_path'])) {
        $filePath = $fileData['result']['file_path'];
        $fileDownloadUrl = "https://api.telegram.org/file/bot$botToken/$filePath";

        // Temporary local path to store the file
        $localPath = sys_get_temp_dir() . '/' . basename($filePath);

        error_log("[DEBUG] Downloading file from Telegram URL: $fileDownloadUrl");

        if (file_put_contents($localPath, file_get_contents($fileDownloadUrl))) {
            error_log("[SUCCESS] File downloaded successfully to $localPath");
            $callback($localPath); // Process the file
            unlink($localPath); // Clean up the temporary file
        } else {
            error_log("[ERROR] Failed to download file from Telegram URL: $fileDownloadUrl");
        }
    } else {
        error_log("[ERROR] Failed to retrieve file info from Telegram for fileId: $fileId. Response: " . json_encode($fileData));
    }
}

function processPhotoToBase64($filePath) {
    if (!file_exists($filePath)) {
        error_log("[ERROR] File does not exist: $filePath");
        return ['success' => false, 'error' => 'File does not exist'];
    }

    // Get MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    error_log("[DEBUG] MIME type of file $filePath: $mimeType");

    // Check if the file is an image
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
        error_log("[ERROR] Unsupported file type for $filePath. MIME: $mimeType");
        return ['success' => false, 'error' => 'Unsupported file type'];
    }

    // Convert to Base64 with MIME type prefix
    $base64Data = base64_encode(file_get_contents($filePath));
    if (!$base64Data) {
        error_log("[ERROR] Failed to convert file $filePath to Base64.");
        return ['success' => false, 'error' => 'Failed to process image'];
    }

    $base64String = "data:$mimeType;base64,$base64Data";
    error_log("[DEBUG] Successfully converted file $filePath to Base64.");
    return ['success' => true, 'data' => $base64String];
}

function findUserIndexById($data, $id) {
    foreach ($data as $index => $user) {
        if ($user['id'] === $id) {
            return $index;
        }
    }
    return -1; // User not found
}
function getUsernameByChatId($chatId) {
    global $pdo; // Ensure the PDO instance is accessible

    try {
        $stmt = $pdo->prepare("SELECT username FROM Gay WHERE id = ?");
        $stmt->execute([$chatId]);
        $username = $stmt->fetchColumn();

        if ($username) {
            return ltrim($username, '@'); // Remove the '@' symbol if present
        }
        return null;
    } catch (Exception $e) {
        error_log("[ERROR] Failed to fetch username for chatId $chatId: " . $e->getMessage());
        return null;
    }
}



function getUserFilePath($username) {
    $directory = __DIR__ . '/'; // Directory containing the files
    $files = glob($directory . 'resellers_data*.json'); // Search for all matching JSON files

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (isset($data['id']) && $data['id'] === $username) {
            return $file; // Return the file path if username matches
        }
    }

    // If no file is found, create a new one
    return createNewFile($username);
}




function createNewFile($username) {
    $directory = __DIR__ . '/';
    $files = glob($directory . 'resellers_data*.json');
    $newFileNumber = count($files) + 1; // Increment file number
    $newFilePath = $directory . 'resellers_data' . $newFileNumber . '.json';

    $newUser = [
        "id" => $username,
        "name" => $username,
        "avatar" => null,
        "contacts" => [],
        "paymentMethods" => [],
        "tags" => []
    ];

    file_put_contents($newFilePath, json_encode($newUser, JSON_PRETTY_PRINT)); // Create the file
    return $newFilePath;
}

function handleAvatar($chatId, $photo) {
    $username = getUsernameByChatId($chatId);
    if (!$username) {
        sendMessage($chatId, "⚠️ Username not found for your ID.");
        return;
    }

    $filePath = getUserFilePath($username); // Check if user file exists

    if ($filePath === null) {
        $filePath = createNewFile($username); // Create a new file if user is not found
        sendMessage($chatId, "⚠️ New user created. Please send the photo again to update avatar.");
    }

    $data = json_decode(file_get_contents($filePath), true);

    // Validate and process the photo
    if (is_array($photo) && !empty($photo)) {
        $fileId = end($photo)['file_id'];
        downloadFile($fileId, function ($downloadPath) use (&$data, $filePath, $chatId) {
            $result = processPhotoToBase64($downloadPath);
            if ($result['success']) {
                $data['avatar'] = $result['data'];
                file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)); // Update the file
                sendMessage($chatId, "✅ Avatar updated successfully. Type /start to Restart The Bot");
                       handleResellerMenu($chatId, $messageId);
                       } else {
                sendMessage($chatId, "⚠️ Failed to process avatar.");
            }
        });
    }
}



function handleDescription($chatId, $text) {
    $username = getUsernameByChatId($chatId);
    if (!$username) {
        sendMessage($chatId, "⚠️ Username not found for your ID.");
        return;
    }

    $filePath = getUserFilePath($username);

    if ($filePath === null) {
        $filePath = createNewFile($username);
        sendMessage($chatId, "⚠️ New user created. Please send payment methods again to update.");
    }

    $data = json_decode(file_get_contents($filePath), true);

    // Validate and process payment methods
    if (!empty($text)) {
        $newMethods = array_map('trim', explode(',', $text)); // Split and trim the input
        $data['paymentMethods'] = array_unique(array_merge($data['paymentMethods'], $newMethods)); // Merge without duplicates
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)); // Save updated data
        sendMessage($chatId, "✅ Payment methods updated successfully.");
    
        handleResellerMenu($chatId, $messageId);
    } else {
        sendMessage($chatId, "⚠️ Please provide valid payment methods.");
    }
}

function showSocialLinksMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✆ WhatsApp', 'callback_data' => 'social_whatsapp'],
                ['text' => '👾 Discord', 'callback_data' => 'social_discord']
            ],
            [
                ['text' => 'ƒ Facebook', 'callback_data' => 'social_facebook'],
                ['text' => '➣ Telegram', 'callback_data' => 'social_telegram']
            ],
            [
                ['text' => '🔙 Back', 'callback_data' => 'reseller_menu']
            ]
        ]
    ];
sendVideo($chatId, "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4","📲 <b>Select the platform to update:</b>", $keyboard);
}

function processUserMessage($chatId, $messageText, $stateFile) {
    $state = loadJson($stateFile);

    if (isset($state[$chatId]) && $state[$chatId]['action'] === 'update_social') {
        $platform = $state[$chatId]['platform'];
        $url = trim($messageText);

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            sendMessage($chatId, "⚠️ Invalid URL format. Please try again.");
            error_log("[ERROR] Invalid URL: $url for chatId: $chatId");
            return;
        }

        // Fetch the username
        $username = getUsernameByChatId($chatId);
        if (!$username) {
            sendMessage($chatId, "⚠️ Username not found. Please try again.");
            error_log("[ERROR] Username not found for chatId: $chatId");
            return;
        }

        // Get or create the user's file
        $filePath = getUserFilePath($username);

        // Load user data
        $data = json_decode(file_get_contents($filePath), true);

        // Update or add the contact
        $found = false;
        foreach ($data['contacts'] as &$contact) {
            if ($contact['type'] === $platform) {
                $contact['url'] = $url; // Update existing entry
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['contacts'][] = ['type' => $platform, 'url' => $url]; // Add new entry
        }

        // Save updated data
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
        sendMessage($chatId, "✅ $platform link updated successfully to: $url");
        error_log("[SUCCESS] $platform link updated for chatId: $chatId");

        // Clear user state
        unset($state[$chatId]);
        saveJson($stateFile, $state);
         handleResellerMenu($chatId, $messageId);
    } else {
        sendMessage($chatId, "⚠️ Unrecognized input. Please try again.");
        error_log("[ERROR] Unrecognized input or missing state for chatId: $chatId");
    }
}




function saveUserDetails($chatId, $username) {
    global $pdo;

    $formattedUsername = ($username !== "unknown") ? "@$username" : $username;
    $trackId = uniqid("track_"); // Generate a unique track ID

    $sql = "INSERT INTO Gay (id, username, role, balance, track_id)
            VALUES (:id, :username, 'user', 0, :track_id)
            ON DUPLICATE KEY UPDATE username = :username";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $chatId,
        ':username' => $formattedUsername,
        ':track_id' => $trackId
    ]);
}



// Admin Menu
function showAdminMenu($chatId) {
    global $pdo;

    // Fetch statistics
    $totalUsersQuery = "SELECT COUNT(*) as total_users FROM Gay";
    $stmt = $pdo->query($totalUsersQuery);
    $totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];

    $totalBalanceQuery = "SELECT SUM(balance) as total_balance FROM Gay WHERE role = 'user'";
    $stmt = $pdo->query($totalBalanceQuery);
    $totalBalance = $stmt->fetch(PDO::FETCH_ASSOC)['total_balance'] ?? 0;

    // Fetch the total keys from the database
    $keysQuery = "SELECT SUM(JSON_LENGTH(value)) as total_keys FROM stockkeys";
    $stmt = $pdo->query($keysQuery);
    $totalKeys = $stmt->fetch(PDO::FETCH_ASSOC)['total_keys'] ?? 0;

    // Step 1: Check Database Connection
    try {
        $pdo->query("SELECT 1");  // Simple query to check DB connectivity
        $dbStatus = "✅ Database is connected.";
    } catch (Exception $e) {
        $dbStatus = "🚫 Database connection failed: " . $e->getMessage();
    }

    // Step 2: Ping the Server and Measure Latency using getMe
    $startTime = microtime(true);  // Record start time
    $pingStatus = pingTelegramAPI();  // Ping the Telegram API using getMe
    $endTime = microtime(true);  // Record end time
    $latency = round(($endTime - $startTime) * 1000);  // Latency in milliseconds

    // Step 3: Determine server status based on latency
    if ($latency > 3000) {
        $serverStatus = "🚫 Latency too high: {$latency} ms. Check your server!";
    } elseif ($latency > 2000) {
        $serverStatus = "🚫 Server is not responsive. Latency: {$latency} ms.";
    } elseif ($latency >= 1 && $latency <= 1200) {
        $serverStatus = "✅ Server is responsive. Latency: {$latency} ms";
    } else {
        $serverStatus = "⚠️ Latency is abnormal: {$latency} ms.";
    }

    // Step 4: Optional - Check External APIs (if any)
    $apiStatus = checkExternalAPIStatus();  // Check external API availability
    $apiStatusMessage = $apiStatus ? "✅ External APIs are available." : "🚫 External APIs are down.";

    // Step 5: Prepare the message with all status information
    $text = <<<HTML
<b>⚙️ Welcome to the Admin Panel!</b>

📊 <b>Statistics</b>:
- Total Users: <b>{$totalUsers}</b>
- Total Balance (Users Only): <b>\${$totalBalance}</b>
- Available Keys: <b>{$totalKeys}</b>

<b>⚙️ Server Latency:</b>

$dbStatus
$serverStatus

Manage and oversee bot operations efficiently using the options below:
HTML;


   $keyboard = [
    'inline_keyboard' => [
        [
            ['text' => "💵 Balance", 'callback_data' => 'balance_op'],
            ['text' => "👥 Roles", 'callback_data' => 'change_role'],
            ['text' => "➕ Add Keys", 'callback_data' => 'add_keys'],
        ],
        [
            ['text' => "🔑 View Keys", 'callback_data' => 'show_keys'],
            ['text' => "📋 View Data", 'callback_data' => 'download_data_json'],
            ['text' => "🚫 Ban/Unban", 'callback_data' => 'ban_unban'],
        ],
        [
            ['text' => "📤 Broadcast", 'callback_data' => 'broadcast'],
            ['text' => "📤 Broadcast(R)", 'callback_data' => 'broadcast_R'],
            ['text' => "🛜 Server Check", 'callback_data' => 'system_health'],
        ],
        [
            ['text' => "⬅️  Back", 'callback_data' => 'main_menu'], // You can add spaces or emojis to make it stand out
        ]
    ]
];

sendMessage($chatId, $text, $keyboard);
}
//ban unban

function BalanceOption($chatId) {
    $text = "Please choose an option to adding balance to users:";
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "Add Balance", 'callback_data' => 'add_balance']],
            [['text' => "Deduct Balance", 'callback_data' => 'deduct_balance']],
        ]
    ];
    sendMessage($chatId, $text, $keyboard);
}


function BanUnban($chatId) {
    $text = "Please choose an option to manage users:";
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "🚫 Ban User", 'callback_data' => 'ban_user']],
            [['text' => "🔓 Unban User", 'callback_data' => 'unban_user']],
        ]
    ];
    sendMessage($chatId, $text, $keyboard);
}
function isUserBanned($chatId) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT banned FROM Gay WHERE id = :chatId");
    $stmt->execute([':chatId' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user && $user['banned'] == 1; // Return true if banned
}
function processBanUnban($chatId, $text, &$state) {
    global $pdo;

    // Step 1: Validate action in state
    if (!isset($state[$chatId]['action'])) {
        sendMessage($chatId, "❌ No action selected. Please use the bot menu to choose 'Ban' or 'Unban'.");
        return;
    }

    $action = $state[$chatId]['action'];
    $bannedValue = ($action === 'ban') ? 1 : 0;

    // Step 2: Check if input is username or ID
    if (strpos($text, '@') === 0) {
        // Treat as username
        $sql = "SELECT * FROM Gay WHERE username = :username";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':username' => ltrim($text, '@')]); // Remove '@'
    } else {
        // Treat as user ID
        $sql = "SELECT * FROM Gay WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => (int)$text]); // Ensure input is an integer
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendMessage($chatId, "❌ User not found with input <b>$text</b>. Please try again with a valid User ID or @username.");
        return;
    }

    $userId = $user['id'];

    // Step 3: Perform the action (ban/unban)
    $sql = "UPDATE Gay SET banned = :banned WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':banned' => $bannedValue,
        ':id' => $userId
    ]);

    // Debugging log
    error_log("User ID: $userId updated with banned = $bannedValue");

    // Step 4: Clear the state and send confirmation
    unset($state[$chatId]);
    saveJson("state.json", $state);

    $actionText = ($bannedValue === 1) ? "banned" : "unbanned";
    sendMessage($chatId, "✅ User <b>{$user['username']}</b> (ID: <b>{$userId}</b>) has been <b>{$actionText}</b>.");
}



function systemHealthCheck($chatId) {
    global $pdo;

    // Step 1: Check Database Connection
    try {
        $pdo->query("SELECT 1");  // Simple query to check DB connectivity
        $dbStatus = "✅ Database is connected.";
    } catch (Exception $e) {
        $dbStatus = "🚫 Database connection failed: " . $e->getMessage();
    }

    // Step 2: Ping the Server and Measure Latency using getMe
    $startTime = microtime(true);  // Record start time
    $pingStatus = pingTelegramAPI();  // Ping the Telegram API using getMe
    $endTime = microtime(true);  // Record end time
    $latency = round(($endTime - $startTime) * 1000);  // Latency in milliseconds

    // Step 3: Determine server status based on latency
    if ($latency > 3000) {
        $serverStatus = "🚫 Latency too high: {$latency} ms. Check your server!";
    } elseif ($latency > 2000) {
        $serverStatus = "🚫 Server is not responsive. Latency: {$latency} ms.";
    } elseif ($latency >= 1 && $latency <= 1200) {
        $serverStatus = "✅ Server is responsive. Latency: {$latency} ms";
    } else {
        $serverStatus = "⚠️ Latency is abnormal: {$latency} ms.";
    }

    // Step 4: Optional - Check External APIs (if any)
    $apiStatus = checkExternalAPIStatus();  // Check external API availability
    $apiStatusMessage = $apiStatus ? "✅ External APIs are available." : "🚫 External APIs are down.";

    // Step 5: Prepare the message with all status information
    $text = <<<HTML
<b>⚙️ System Health Check:</b>

$dbStatus
$serverStatus
$apiStatusMessage
HTML;

    // Send the system health status to the admin
    sendMessage($chatId, $text);
}

// Function to ping Telegram API using getMe
function pingTelegramAPI() {
    $url = "https://api.telegram.org/bot" . botToken . "/getMe";  // Replace botToken with your bot's token
    $response = file_get_contents($url);  // Make the API request

    if ($response) {
        $data = json_decode($response, true);
        return isset($data['ok']) && $data['ok'] === true;  // Check if the response is successful
    } else {
        return false;  // If the request fails, return false
    }
}

// Optional: Check external API status (if applicable)
function checkExternalAPIStatus() {
    $externalApiUrl = "149.102.149.146/ArtixKrieger/callbacktyioapjagtajiwywjoasj.php";  // Replace with actual API URL

    // Use cURL for more robust error handling
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $externalApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Set a timeout of 10 seconds for the request

    // Disable SSL verification (for testing purposes, do this only if you know the API is secure)
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    // Check for errors in the cURL request
    if(curl_errno($ch)) {
        // Log or handle the cURL error
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    // Check if the response is not empty
    if (empty($response)) {
        return false;
    }

    // Decode the JSON response
    $data = json_decode($response, true);

    // Ensure the response is valid and contains the expected 'status' field
    return isset($data['status']) && $data['status'] == 'ok';
}
// Balance Menu
function showUserBalanceMenu($chatId, $userId) {
    // Use the global $pdo object for the database connection
    global $pdo;

    // Query the database for the user's balance
    $query = "SELECT balance FROM Gay WHERE id = ?";
    $stmt = $pdo->prepare($query);

    if ($stmt === false) {
        sendMessage($chatId, "❌ Unable to prepare the database query. Please contact support.");
        return;
    }

    // Bind the user ID to the prepared statement and execute
    $stmt->bindParam(1, $userId, PDO::PARAM_INT);
    $stmt->execute();

    // Check if the user exists
    if ($stmt->rowCount() == 0) {
        sendMessage($chatId, "❌ User not found or unable to retrieve balance. Please contact support.");
        return;
    }

    // Fetch the result
    $balance = $stmt->fetchColumn();

    // Prepare the keyboard for the menu
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "🌟 Deposit", 'callback_data' => 'deposit']],
            [['text' => "⬅️ Back", 'callback_data' => 'main_menu']],
        ]
    ];

    // Send the balance to the user
    
    
    
sendVideo($chatId, "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4","💰 <b>Your Balance: $$balance</b>\n\nYou can make a deposit to add balance for purchasing the keys.", $keyboard);
}

// Hack Menu
function showHackMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "⬇️ Apk Non Root", 'callback_data' => 'apk_no_root'], ['text' => "⬇️ Apk Root", 'callback_data' => 'apk_root']],
            [['text' => "🔑 Buy Keys", 'callback_data' => 'buy_keys']],
            [['text' => "⬅️ Back", 'callback_data' => 'main_menu']],
        ]
    ];
  sendVideo($chatId, "https://t.me/ArtixKriegerCH/1505", 
    "🏆 <b>Easy Victory Cracked Version 55.4.3</b> 🏆\n\n" .
    "🚀 <b>Unlock Your Winning Potential!</b> 🚀\n\n" .
    "📌 <i>Features:</i>\n" .
    "🔸 <b>Auto Play</b> - <i>Autopilot!</i>\n" .
    "🔸 <b>Humanized Aim</b> - <i>Aim like a pro</i>\n" .
    "🔸 <b>Auto Queue</b> - <i>Never wait again</i>\n" .
    "🔸 <b>Auto Aim</b> - <i>Hit your targets effortlessly</i>\n" .
    "🔸 <b>Fast Mode</b> - <i>Speed up your gameplay</i>\n" .
    "🔸 <b>Pockets Manually</b> - <i>Control your shots</i>\n" .
    "🔸 <b>Play Golden Shot</b> - <i>Take your best shot</i>\n" .
    "🔸 <b>Humanized Power</b> - <i>Perfect shots</i>\n" .
    "🔸 <b>Humanization</b> - <i>Real player Gameplay</i>\n" .
    "🔸 <b>Find the Best Shot</b> - <i>Optimize gameplay</i>\n" .
    "🔸 <b>Full Power at Break</b> - <i>Maximize impact</i>\n\n\n" .
    "🔥 <b>Get ready to dominate the game!</b> 🔥",
    $keyboard);
}
// Key Purchase Menu
function showKeyPurchaseMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "3 Days - $3", 'callback_data' => 'key_3_days'], ['text' => "7 Days - $5", 'callback_data' => 'key_7_days']],
            [['text' => "10 Days - $8", 'callback_data' => 'key_10_days'], ['text' => "20 Days - $10", 'callback_data' => 'key_20_days']],
            [['text' => "30 Days - $13", 'callback_data' => 'key_30_days'], ['text' => "40 Days - $15", 'callback_data' => 'key_40_days']],
            [['text' => "⬅️ Back", 'callback_data' => 'hack_menu']],
        ]
    ];

     sendVideo($chatId, "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4","🌟 <b>𝘽𝙪𝙮 𝙏𝙝𝙚 𝙇𝙖𝙩𝙚𝙨𝙩 𝙐𝙡𝙩𝙞𝙢𝙖 𝙃𝙖𝙘𝙠𝙨!</b> 🌟\n\n𝙂𝙚𝙩 𝙖𝙘𝙘𝙚𝙨𝙨 𝙩𝙤 𝙋𝙧𝙚𝙢𝙞𝙪𝙢 𝙝𝙖𝙘𝙠𝙨 𝙛𝙤𝙧 𝙘𝙝𝙚𝙖𝙥 𝙥𝙧𝙞𝙘𝙚! \n\n<b>𝘾𝙝𝙤𝙤𝙨𝙚 𝙮𝙤𝙪𝙧 𝙠𝙚𝙮 𝙩𝙤 𝙥𝙪𝙧𝙘𝙝𝙖𝙨𝙚:</b>\n\n3 Days - $3\n7 Days - $5\n10 Days - $8\n20 Days - $10\n30 Days - $13\n40 Days - $15", $keyboard, 'HTML');
}
// Handle Key Purchases
function handleKeyPurchase($chatId, $userId, $keyType) {
    global $pdo;

    // Define prices for each key duration
    $price = [
        "3_days" => 3, 
        "7_days" => 5, 
        "10_days" => 8,
        "20_days" => 10, 
        "30_days" => 13, 
        "40_days" => 15
    ];

    // Validate $keyType
    if (!is_string($keyType) || !isset($price[$keyType])) {
        sendMessage($chatId, "❌ Invalid key type <b>$keyType</b>. Please contact support.");
        return;
    }

    $keyPrice = $price[$keyType]; // Get the price for the selected key type

    // Step 1: Fetch user balance from the database
    $sql = "SELECT balance FROM Gay WHERE id = :userId";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':userId' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendMessage($chatId, "❌ User not found. Please contact support.");
        return;
    }

    $userBalance = floatval($user['balance']); // Convert balance to float

    // Step 2: Check if the user has enough balance
    if ($userBalance < $keyPrice) {
        sendMessage($chatId, "❌ Insufficient balance! Your balance is <b>$$userBalance</b>, but the key costs <b>$$keyPrice</b>.");
        return;
    }

    // Step 3: Fetch an available key from stockkeys
    echo "Fetching key for duration: $keyType";  // Debugging statement

    $sql = "SELECT * FROM stockkeys WHERE duration = :duration LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':duration' => $keyType]);
    $keyData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$keyData) {
        echo "No data found for key type: $keyType";  // Debugging statement
        sendMessage($chatId, "❌ No keys available for <b>$keyType</b>. Please contact support.");
        return;
    }

    echo "Key found: " . print_r($keyData, true);  // Debugging statement

    // If no key data or empty value, inform the user
    if (empty($keyData['value'])) {
        sendMessage($chatId, "❌ No keys available for <b>$keyType</b>. Please contact support.");
        return;
    }

   $keys = json_decode($keyData['value'], true);

    if (json_last_error() !== JSON_ERROR_NONE) {
    sendMessage($chatId, "❌ Failed to decode the key data. Please contact support.");
    return;
}

    if (empty($keys)) {
    sendMessage($chatId, "❌ No available keys for <b>$keyType</b>. Please contact support.");
    return;
}

    $key = array_shift($keys);

    $updatedKeys = json_encode($keys);

    // Step 4: Update the stockkeys table
    $sql = "UPDATE stockkeys SET value = :value WHERE duration = :duration";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':value' => $updatedKeys,
        ':duration' => $keyType
    ]);

    // Step 5: Deduct the balance and update the user's balance in the database
    $newBalance = $userBalance - $keyPrice;
    $sql = "UPDATE Gay SET balance = :balance WHERE id = :userId";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':balance' => $newBalance,
        ':userId' => $userId
    ]);

    // Step 6: Convert the key type to a user-friendly format
    $keyTypeFormatted = str_replace("_", " ", $keyType);
    $keyTypeFormatted = ucwords($keyTypeFormatted); // E.g., "3_days" becomes "3 Days"

    // Step 7: Send success message
    $caption = "<b>✅ Purchase Successful!</b>\n\nPlan: {$keyTypeFormatted}\nMod: Easy Victory Crack Version 55.4.3\n\nKey: <tg-spoiler>{$key}</tg-spoiler>";
    sendVideo(
        $chatId, 
        "https://v1.pinimg.com/videos/mc/720p/2a/95/29/2a9529cfadf241d3cb28ceaae7b6aa9e.mp4", $caption
    );
}

// Handle Add Balance
function handleAddBalance($chatId, $text, &$state) {
    global $pdo;

    if (!isset($state[$chatId]['target_id'])) {
        // Step 1: Check if the provided text is a valid user ID in the database
        $sql = "SELECT * FROM Gay WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $text]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($targetUser) {
            // If the user exists, save their ID in the state
            $state[$chatId]['target_id'] = $text;
            saveJson("state.json", $state); // Still saving `state.json` for ongoing actions
            sendMessage($chatId, "Now send me the amount to add to <b>{$text}</b>'s balance:");
        } else {
            // If user ID is not found
            sendMessage($chatId, "User ID $text not found in the database. Please try again.");
        }
    } elseif (!isset($state[$chatId]['amount'])) {
        // Step 2: Handle balance addition
        $amount = (int)$text;

        if ($amount > 0) {
            $targetId = $state[$chatId]['target_id'];

            // Fetch target user's balance
            $sql = "SELECT balance FROM Gay WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $targetId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                // Add balance to the user's current balance
                $newBalance = $targetUser['balance'] + $amount;

                // Update the balance in the database
                $sql = "UPDATE Gay SET balance = :balance WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':balance' => $newBalance, ':id' => $targetId]);

                // Clear the state and confirm the operation
                unset($state[$chatId]);
                saveJson("state.json", $state); // Optional, depending on your workflow
                sendMessage($chatId, "✅ Added $amount to <b>{$targetId}</b>'s balance. New balance: <b>$newBalance</b>.");
            } else {
                sendMessage($chatId, "❌ User not found. Please restart the process.");
            }
        } else {
            // Invalid amount
            sendMessage($chatId, "❌ Invalid amount. Please send a positive number.");
        }
    }
}


//deduct balance
function handleDeductBalance($chatId, $text, &$state) {
    global $pdo;

    if (!isset($state[$chatId]['target_id'])) {
        // Step 1: Check if the provided text is a valid user ID in the database
        $sql = "SELECT * FROM Gay WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $text]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($targetUser) {
            // If the user exists, save their ID in the state
            $state[$chatId]['target_id'] = $text;
            saveJson("state.json", $state); // Still saving `state.json` for ongoing actions
            sendMessage($chatId, "Now send me the amount to deduct from <b>{$text}</b>'s balance:");
        } else {
            // If user ID is not found
            sendMessage($chatId, "User ID $text not found in the database. Please try again.");
        }
    } elseif (!isset($state[$chatId]['amount'])) {
        // Step 2: Handle balance deduction
        $amount = (int)$text;

        if ($amount > 0) {
            $targetId = $state[$chatId]['target_id'];

            // Fetch target user's balance
            $sql = "SELECT balance FROM Gay WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $targetId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                $currentBalance = $targetUser['balance'];

                if ($currentBalance >= $amount) {
                    // Deduct balance from the user's current balance
                    $newBalance = $currentBalance - $amount;
                } else {
                    // If amount to deduct is greater than balance, set balance to 0
                    $newBalance = 0;
                }

                // Update the balance in the database
                $sql = "UPDATE Gay SET balance = :balance WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':balance' => $newBalance, ':id' => $targetId]);

                // Clear the state and confirm the operation
                unset($state[$chatId]);
                saveJson("state.json", $state); // Optional, depending on your workflow
                sendMessage($chatId, "✅ Deducted $amount from <b>{$targetId}</b>'s balance. New balance: <b>$newBalance</b>.");
            } else {
                sendMessage($chatId, "❌ User not found. Please restart the process.");
            }
        } else {
            // Invalid amount
            sendMessage($chatId, "❌ Invalid amount. Please send a positive number.");
        }
    }
}



// Handle Payment Invoice

function showKeys($chatId) {
    global $pdo;

    // Step 1: Query the database for all available keys
    $sql = "SELECT * FROM stockkeys";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    // Step 2: Fetch all the rows
    $keysData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if we have any data
    if (empty($keysData)) {
        sendMessage($chatId, "❌ No keys available in the database.");
        return;
    }

    // Start building the message
    $message = "🔑 <b>Available Keys</b>\n\n";

    // Step 3: Loop through each row (duration and keys)
    foreach ($keysData as $row) {
        $duration = $row['duration']; // Duration (3_days, 7_days, etc.)
        $keys = json_decode($row['value'], true); // Decoding the JSON value to get the keys

        // Append the duration to the message
        $message .= "⏳ <b>" . ucfirst(str_replace('_', ' ', $duration)) . "</b>\n";

        // Check if there are any keys for this duration
        if (!empty($keys)) {
            foreach ($keys as $key) {
                $message .= "• $key\n"; // Add the key to the list
            }
        } else {
            $message .= "No keys available.\n";
        }

        $message .= "\n"; // Add spacing between durations
    }

    // Step 4: Send the formatted message to the user
    sendMessage($chatId, $message);
}




function showDepositMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "💵 Crypto", 'callback_data' => '/crypto'],
                ['text' => "💴 PayPal", 'callback_data' => '/paypal']
            ],

            [['text' => "🔙 Back", 'callback_data' => '/back']],
        ]
    ];

    sendMessage($chatId, "<b>💳 Choose Your Payment Mode</b>\n\n" .
               "Select one of the following options to deposit balance:\n" .
               "• <b>💰 Crypto:</b> Fast and secure transactions with digital currency.\n" .
               "• <b>💵 PayPal:</b> Convenient and trusted payment option worldwide.\n\n" .
               "Tap your preferred method below to continue.", $keyboard);
}
function showPayPalMenu($chatId) {
sendMessage($chatId, "Select a deposit amount for PayPal:\n\n🚧 This feature is currently under construction. Stay tuned for updates!");

}


// Crypto Deposit Menu
function showCryptoMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "$3", 'callback_data' => 'crypto_3'], ['text' => "$7", 'callback_data' => 'crypto_7'], ['text' => "$10", 'callback_data' => 'crypto_10']],
            [['text' => "$20", 'callback_data' => 'crypto_20'], ['text' => "$30", 'callback_data' => 'crypto_30'], ['text' => "$40", 'callback_data' => 'crypto_40']],
            [['text' => "⬅️ Back", 'callback_data' => 'deposit']],
        ]
    ];

    // Send the message first
    sendMessage(
        $chatId, 
        "<b>💰 Select a Deposit Amount</b>\n\nChoose your desired amount to proceed with your crypto deposit. Tap an option below:", 
        $keyboard
    );
}





// Show Role Selection Menu
function showRoleSelectionMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "👨‍💻 resellers", 'callback_data' => 'role_resellers']],
            [['text' => "👤 Admin", 'callback_data' => 'role_admin']],
            [['text' => "👥 User", 'callback_data' => 'role_user']],
            [['text' => "⬅️ Back", 'callback_data' => 'admin_menu']],
        ]
    ];

    sendMessage($chatId, "Select a role to assign:", $keyboard);
}

// Handle Change Role
function handleChangeRole($chatId, $text, &$state) {
    global $pdo;

    // Step 1: Check if a role is selected
    if (!isset($state[$chatId]['role'])) {
        sendMessage($chatId, "❌ Role not selected. Please start over.");
        return;
    }

    $newRole = $state[$chatId]['role'];

    // Step 2: Determine if input is username or ID
    if (strpos($text, '@') === 0) {
        // If input starts with '@', treat it as a username
        $sql = "SELECT * FROM Gay WHERE username = :username";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':username' => $text]);
    } else {
        // Otherwise, treat it as a user ID
        $sql = "SELECT * FROM Gay WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $text]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // If no user is found, ask to retry with ID
        sendMessage($chatId, "❌ User not found with input <b>$text</b>. If you know their ID, please provide it to continue.");
        return;
    }

    // Step 3: Update the role in the database
    $userId = $user['id']; // Extract the user ID from the result
    $sql = "UPDATE Gay SET role = :role WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':role' => $newRole,
        ':id' => $userId
    ]);

    // Step 4: Clear the state and confirm the operation
    unset($state[$chatId]);
    saveJson("state.json", $state); // Still saving `state.json` for ongoing actions

    sendMessage($chatId, "✅ Role for user <b>{$text}</b> (ID: <b>{$userId}</b>) changed to <b>{$newRole}</b>.");
}

// Add Keys Menu
function showAddKeysMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "3 Days", 'callback_data' => 'add_key_3_days'], ['text' => "7 Days", 'callback_data' => 'add_key_7_days'], ['text' => "10 Days", 'callback_data' => 'add_key_10_days']],
            [['text' => "20 Days", 'callback_data' => 'add_key_20_days'], ['text' => "30 Days", 'callback_data' => 'add_key_30_days'], ['text' => "40 Days", 'callback_data' => 'add_key_40_days']],
            [['text' => "⬅️ Back", 'callback_data' => 'admin_menu']],
        ]
    ];

    sendMessage($chatId, "Select the duration to add keys:", $keyboard);
}

// Handle Add Keys
function handleAddKeys($chatId, $text, &$state) {
    global $pdo;

    // Step 1: Check if a key duration is selected
    if (!isset($state[$chatId]['key_duration'])) {
        sendMessage($chatId, "❌ Key duration not selected. Please start over.");
        return;
    }

    $keyDuration = $state[$chatId]['key_duration'];

    // Step 2: Split the input into multiple keys
    $keys = preg_split('/[\s]+/', trim($text)); // Split by spaces or new lines
    $keys = array_filter($keys, function($key) {
        return !empty($key);
    }); // Remove empty entries

    if (empty($keys)) {
        sendMessage($chatId, "❌ No valid keys detected. Please provide valid keys.");
        return;
    }

    // Step 3: Fetch existing keys for the selected duration
    $sql = "SELECT * FROM stockkeys WHERE duration = :duration";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':duration' => $keyDuration]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $existingKeys = $row ? json_decode($row['value'], true) : [];
    $existingKeys = is_array($existingKeys) ? $existingKeys : [];

    // Step 4: Filter out duplicate keys
    $newKeys = array_diff($keys, $existingKeys); // Exclude keys already in the database

    if (empty($newKeys)) {
        sendMessage($chatId, "❌ All provided keys are already added to <b>{$keyDuration}</b>.");
        return;
    }

    // Step 5: Update or insert the keys in the database
    $updatedKeys = array_merge($existingKeys, $newKeys);
    $updatedKeysJson = json_encode($updatedKeys);

    if ($row) {
        $updateSql = "UPDATE stockkeys SET value = :value WHERE duration = :duration";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':value' => $updatedKeysJson,
            ':duration' => $keyDuration
        ]);
    } else {
        $insertSql = "INSERT INTO stockkeys (duration, value) VALUES (:duration, :value)";
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([
            ':duration' => $keyDuration,
            ':value' => $updatedKeysJson
        ]);
    }

    // Step 6: Notify the user of successfully added keys
    $addedKeysCount = count($newKeys);
    $addedKeysText = implode(', ', $newKeys);
    sendMessage($chatId, "✅ Successfully added {$addedKeysCount} key(s) to <b>{$keyDuration}</b>: <b>{$addedKeysText}</b>");

    // Clear the state after adding the keys
    unset($state[$chatId]);
    saveJson("state.json", $state);
}

function handleJoinedCallback($callbackQueryId, $chatId, $userId, $roles) {
    $channelId = '@ArtixKriegerCH'; // Replace with your channel username

    if (checkChannelMembership($chatId, $userId, $channelId)) {
        // If the user is a member, show the main menu
        showUserMenu($chatId, isAdmin($userId, $roles));
    } else {
        // If the user is not a member, send an alert
        sendAlert($callbackQueryId, "❌ You are not a member of the channel! Please join @ArtixKriegerCH to continue.");
    }
}

//security testing
function isFlooding($chatId, $timeWindow = 5, $maxRequests = 5) {
    global $pdo;
    
    // Get the current timestamp
    $currentTime = time();
    
    // Fetch user data from the database
    $stmt = $pdo->prepare("SELECT flood_last_request, flood_warning_count, banned, role FROM Gay WHERE id = :chatId");
    $stmt->execute(['chatId' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user exists
    if (!$user) {
        return false; // User not found
    }

    // If the user is banned, stop further processing
    if ($user['banned'] == 1) {
        return false;
    }

    // If the user is an admin, skip the flooding check (admins can't be banned)
    if ($user['role'] === 'admin') {
        return false; // Admins cannot be banned or warned
    }
 if ($user['role'] === 'resellers') {
        return false; // Admins cannot be banned or warned
    }
    // If the user is not flooding, update the last request time and exit
    if ($currentTime - $user['flood_last_request'] > $timeWindow) {
        $stmt = $pdo->prepare("UPDATE Gay SET flood_last_request = :currentTime WHERE id = :chatId");
        $stmt->execute(['currentTime' => $currentTime, 'chatId' => $chatId]);
        return false; // No flooding detected
    }

    // If the user is flooding (making multiple requests in the time window), handle warnings and autoban
    if ($user['flood_warning_count'] < 2) {
        // Increment the warning count
        $newWarningCount = $user['flood_warning_count'] + 1;
        
        // Update the warning count in the database
        $stmt = $pdo->prepare("UPDATE Gay SET flood_warning_count = :newWarningCount WHERE id = :chatId");
        $stmt->execute(['newWarningCount' => $newWarningCount, 'chatId' => $chatId]);
        
        // Send appropriate warning message
        if ($newWarningCount == 1) {
            SendMessage($chatId, "⚠️ **Warning:** You are flooding the chat! ⚠️ 
   
   You have sent too many messages in a short period of time. Please slow down! 🐢

   ⚠️ **1 more warning** and you'll be banned Permanently. 🚫

   ⏳ Take a break and avoid further actions to prevent being banned!");
        } elseif ($newWarningCount == 2) {
            SendMessage($chatId, "Final Warning: You are flooding the chat. You will be banned if you continue.");
        }

        return true; // Flooding detected, warning sent
    } else {
        // If the user has already received 2 warnings, autoban them
        $stmt = $pdo->prepare("UPDATE Gay SET banned = 1 WHERE id = :chatId");
        $stmt->execute(['chatId' => $chatId]);

        // Send a ban message
        SendMessage($chatId, "🚨 **FINAL WARNING:** You are still flooding the chat! 🚨

   You have exceeded the maximum number of requests allowed in a short time. Please stop spamming! ❌

   ⛔ **If you continue, you will be banned** from this chat! ⚠️

   🛑 **We urge you to stop spamming immediately to avoid being banned.**"
);

        // Log the autoban
        error_log("Autoban triggered for user: $chatId due to flooding.");

        return true; // User is banned
    }
}

function logMaliciousAttempt($chatId, $text) {
    $logMessage = sprintf(
        "Malicious input detected from user: %s\nInput: %s\nTimestamp: %s\n",
        $chatId,
        $text,
        date('Y-m-d H:i:s')
    );

    file_put_contents('malicious_attempts.log', $logMessage, FILE_APPEND);
}
function alertAdmins($chatId, $input) {
    global $pdo;
    
    // Get the admin chat IDs (this can be hardcoded or retrieved from the DB)
    $adminChatIds = ['1058646211'];  // Replace with actual admin chat IDs

    foreach ($adminChatIds as $adminChatId) {
        // Send alert to each admin
        sendMessage(
            $adminChatId,
            "🚨 Suspicious activity detected:\nUser: $chatId\nInput: $input\nTimestamp: " . date('Y-m-d H:i:s')
        );
    }
}
function Megaphone2($chatId, $text, &$state) {
    global $pdo;

    // Check if the user is in the broadcast message phase
    if (isset($state[$chatId]['action']) && $state[$chatId]['action'] == 'broadcast_R') {
        // Step 1: Save the message
        $broadcastMessage = $text;

        // Step 2: Send message to all resellers who are not banned
        $sql = "SELECT id FROM Gay WHERE banned = 0 AND role = 'resellers'"; // Filter for resellers only
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sentCount = 0;

        foreach ($users as $user) {
            $targetChatId = $user['id'];
            sendMessage($targetChatId, $broadcastMessage);
            $sentCount++;
        }

        // Notify the admin how many resellers received the message
        sendMessage($chatId, "✅ The message has been sent to $sentCount resellers.");
        
        // Clear the state after the broadcast
        unset($state[$chatId]);
        saveJson("state.json", $state);
    }
}

function Megaphone($chatId, $text, &$state) {
    global $pdo;

    // Check if the user is in the broadcast message phase
    if (isset($state[$chatId]['action']) && $state[$chatId]['action'] == 'broadcast') {
        // Step 1: Save the message
        $broadcastMessage = $text;

        // Step 2: Send message to all non-banned users
        $sql = "SELECT id FROM Gay WHERE banned = 0"; // Exclude banned users
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sentCount = 0;

        foreach ($users as $user) {
            $targetChatId = $user['id'];
            sendMessage($targetChatId, $broadcastMessage);
            $sentCount++;
        }

        // Notify the admin how many users received the message
        sendMessage($chatId, "✅ The message has been sent to $sentCount users.");
        
        // Clear the state after the broadcast
        unset($state[$chatId]);
        saveJson("state.json", $state);
    }
}
function makePostRequest($url, $data) {
    error_log("Making POST Request to $url with data: " . json_encode($data));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("Response: $response, HTTP Code: $httpCode");

    if ($httpCode === 200) {
        return json_decode($response, true);
    }

    error_log("POST Request failed with HTTP Code: $httpCode");
    return null;
}

function sendRequest($method, $params) {
    $botToken = "7754802590:AAHm52rbZz9BvOQOYxnpmgZraZwBxQskxiA"; // Replace with your bot token
    $url = "https://api.telegram.org/bot$botToken/$method";

    // Log the request for debugging
    error_log("Sending Telegram API Request to $method with params: " . print_r($params, true));

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

    // Execute cURL and capture the response
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Log the response for debugging
    error_log("Telegram API Response: HTTP Code: $httpCode, Response: $response");

    // Handle errors
    if ($httpCode !== 200 || !$response) {
        error_log("Telegram API Request failed with HTTP Code: $httpCode, Response: $response");
    }

    curl_close($ch);
    return $response;
}
function getMerchantId($pdo) {
    $sql = "SELECT config_value FROM Config WHERE config_key = 'merchant_id'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        return $result['config_value'];
    } else {
        throw new Exception("Merchant ID not found in the database.");
    }
}

function processCryptoDeposit($data, $user_id, $chat_id, $message_id) {
    global $pdo; 
    $merchant = getMerchantId($pdo);
    $callbackUrl = "149.102.149.146/ArtixKrieger/callbacktyioapjagtajiwywjoasj.php";
    $returnUrl = "https://t.me/ArtixKrieger";
    error_log("Received callback data for crypto deposit: $data");
    if (strpos($data, "crypto_") === 0) {
        $amount = str_replace("crypto_", "", $data); // Extract the numeric part after 'crypto_'
        if (!is_numeric($amount)) {
            // Log error if the amount is invalid
            error_log("Invalid amount in callback data: $data");
            sendMessage($chat_id, "❌ Invalid payment request. Please try again.");
            return false;
        }
    } else {
        // Log error if the format is invalid
        error_log("Invalid callback data format: $data");
        sendMessage($chat_id, "❌ Invalid payment request. Please try again.");
        return false;
    }

    $orderId = uniqid("order_");

    // Log the extracted amount for debugging
    error_log("Extracted amount: $amount");

    // Prepare the request payload
    $payload = [
        "merchant" => $merchant,
        "amount" => $amount,
        "currency" => "USD",
        "callbackUrl" => $callbackUrl,
        "orderId" => $orderId,
        "returnUrl" => $returnUrl,
    ];

    // API endpoint
    $oxaPayApiUrl = "https://api.oxapay.com/merchants/request";

    // Make POST request to OxaPay API
    $response = makePostRequest($oxaPayApiUrl, $payload);

    // Log the API response
    error_log("OxaPay API response: " . print_r($response, true));

    if (
        $response &&
        isset($response["result"]) &&
        $response["result"] === 100 &&
        isset($response["payLink"])
    ) {
        // Extract payment link and track ID
        $payLink = $response["payLink"];

        // Caption and inline keyboard for the payment invoice
        
        $caption = 
            "✨ <b>Deposit Confirmation</b> ✨\n\n" .
            "🔹 <b>Telegram ID:</b> <code>{$user_id}</code>\n" .
            "🔹 <b>Deposit Amount:</b> <b>\$$amount</b>\n\n" .
            "💬 <i>Please review the details carefully:</i>\n\n" .
            "✅ <b>To confirm the deposit, press <b>'Payment Invoice'</b>.</b>\n" .
            "❌ <b>To cancel, press <b>'No'</b>.</b>\n\n" .
            "⚠️ <i>If you have any questions, feel free to contact support!</i>\n\n" .
            "📢 <b><u>Important Disclaimer:</u></b>\n" .
            "<i>All balances in this bot are non-refundable. Please make sure you are confident in your deposit before confirming. We cannot process any refund requests.</i>\n\n" .
            "🙅‍♂️ <i>Do not ask for a refund once the transaction is completed. Thank you for your understanding!</i>";

      
        $inline_keyboard = [
            "inline_keyboard" => [
                [
                    [
                        "text" => "Payment Invoice",
                        "web_app" => [
                            "url" => $payLink, // Use web_app with the generated payLink
                        ],
                    ],
                ],
                [
                    [
                        "text" => "🔙 Back",
                        "callback_data" => "/back",
                    ],
                ],
            ],
        ];

        // Parameters for editing the message
        $params = [
            "chat_id" => $chat_id,
            "message_id" => $message_id,
            "parse_mode" => "HTML",
            "reply_markup" => json_encode($inline_keyboard),
        ];

        // Use editMessageText if the caption doesn't exist
        $params["text"] = $caption; // Set text for the message

        // Send the request
        $response = sendRequest("editMessageText", $params);

        if ($response && strpos($response, '"ok":true') !== false) {
            error_log("Successfully updated Telegram message with payment link.");
            return true;
        } else {
            error_log("Failed to update Telegram message with payment link.");
            sendMessage($chat_id, "❌ Unable to update the payment invoice. Please try again.");
            return false;
        }
    }

    // Log failure and notify the user
    error_log("OxaPay API call failed or returned invalid response.");
    sendMessage($chat_id, "❌ Unable to process your payment request. Please try again.");
    return false;
}
function processPayPalDeposit($data, $user_id, $chat_id, $message_id) {
    // Check if the data starts with 'paypal_' to handle the input format
    if (strpos($data, "paypal_") === 0) {
        // Extract the amount by removing the 'paypal_' part
        $amount = substr($data, strlen("paypal_"));

        // Check if the amount is valid (not empty)
        if (empty($amount) || !is_numeric($amount)) {
            error_log("ERROR: Invalid or empty amount received: $data");
            return; // Exit if price is missing or invalid
        }

        // Proceed with order creation
        $merchant = $user_id;
        $orderId = uniqid("order_$amount" . "_");
        $callbackUrl = "149.102.149.146/ArtixKrieger/callbacktyioapjagtajiwywjoasj.php";
        $returnUrl = "https://t.me/ArtixKrieger";

        // Create PayPal order
        $paypal_order = create_order($orderId, $merchant, $amount);

        // Check if order creation was successful and contains the required link
        if (!$paypal_order || !isset($paypal_order["link"])) {
            error_log("ERROR: PayPal order creation failed or link not found for order ID: $orderId");
            return; // Stop if the order creation fails
        }

        logError("ORDER(Based): " . json_encode($paypal_order, JSON_PRETTY_PRINT));
        
        // Define the inline keyboard for the payment invoice link
        $caption = "<b>🌐 Here is your payment invoice:</b>";
        $inline_keyboard = [
            "inline_keyboard" => [
                [
                    [
                        "text" => "Payment Invoice",
                        "web_app" => ["url" => $paypal_order["link"]]
                    ],
                ],
                [["text" => "🔙 Back", "callback_data" => "/back"]],
            ],
        ];

        // Send the updated message with the payment invoice link
        $params = [
            "chat_id" => $chat_id,
            "message_id" => $message_id,
            "caption" => $caption,
            "parse_mode" => "HTML",
            "reply_markup" => json_encode($inline_keyboard),
        ];
        
        // Send request to update message with the link
        sendRequest("editMessageCaption", $params);

        // Additional functionality for processing payment (if any)
        // You can add other necessary logic for handling payment or further actions.
    } else {
        // If the data does not match expected format
        error_log("ERROR: Invalid data received for PayPal deposit processing: $data");
    }
}


function sanitizeInput($input) {
    // Remove HTML tags and entities
    $input = strip_tags($input);

    // Convert special characters to HTML entities
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

    // Trim unnecessary whitespace
    $input = trim($input);

    return $input;
}

function sendDocument($chatId, $documentUrl) {
    global $botToken;

    $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
    $params = [
        "chat_id" => $chatId,
        "document" => $documentUrl,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

$userStates = [];
function handleCallbackQuery($callbackQuery) {
    global $rolesFile, $stateFile, $keysFile ,$userStates,$pdo;
    $chatId = $callbackQuery['message']['chat']['id'];
    $userId = $callbackQuery['from']['id'];
    $callbackQueryId = $callbackQuery['id'];
    $data = $callbackQuery['data'];
    $roles = loadJson($rolesFile);
    $state = loadJson($stateFile);
    $messageId = $callbackQuery['message']['message_id'];
    $sql = "SELECT banned FROM Gay WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $input = isset($message['text']) ? $message['text'] : ''; 

    if ($user && $user['banned'] == 1) {
        sendMessage($chatId, "❌ You are banned from using this bot.");
        error_log("Blocked access for banned user: $chatId");
        return;}
    if (isFlooding($chatId, $input)) {
    handleFlooding($chatId, $input);
    return;
    }
    if ($data === 'main_menu') {
        deleteMessage($chatId, $messageId);
        autoCheckAndUpdateRoles();
    $channelId = '@ArtixKriegerCH';
    if (checkChannelMembership($chatId, $userId, $channelId)) {
        autoCheckAndUpdateRoles();
         deleteMessage($chatId, $messageId);
        showUserMenu($chatId, isAdmin($userId, $roles));
        deleteMessage($chatId, $messageId);
    } else {
        handleJoinedCallback($callbackQueryId, $chatId, $userId, $roles);
        autoCheckAndUpdateRoles();
    } } else  if (strpos($data, "crypto_") === 0) {
       $result = processCryptoDeposit($data, $userId, $chatId, $messageId);
    if ($result) {
    error_log("Crypto deposit processing succeeded for: " . $data);
    } else {
    error_log("Crypto deposit processing failed for: " . $data);
    } }else  if (strpos($data, "paypal_") === 0) {
        // Process PayPal deposit
        $result = processPayPalDeposit($data, $userId, $chatId, $messageId);

        if ($result) {
            error_log("PayPal deposit processed successfully.");
        } else {
            error_log("Failed to process PayPal deposit.");
        }
    } elseif ($data === 'admin_menu') {
         deleteMessage($chatId, $messageId);
        showAdminMenu($chatId);
    } elseif ($data === 'balance_menu') {
         deleteMessage($chatId, $messageId);
        showUserBalanceMenu($chatId, $userId, $roles);
    } elseif ($data === 'hack_menu') {
         deleteMessage($chatId, $messageId);
        showHackMenu($chatId);
    } elseif ($data === 'buy_keys') {
         deleteMessage($chatId, $messageId);
        showKeyPurchaseMenu($chatId);
    } elseif (strpos($data, 'key_') === 0) {
        $keyType = str_replace('key_', '', $data);
         deleteMessage($chatId, $messageId);
         handleKeyPurchase($chatId, $userId, $keyType);
    } elseif ($data === 'apk_no_root') {
    // Use the Telegram file URL directly
    $documentUrl = "https://t.me/ArtixKriegerCH/2063"; // Replace with your actual document link
    sendDocument($chatId, $documentUrl, "⬇️ Here is the APK Non-Root file.");
    } elseif ($data === 'apk_root') {
    // Use the Telegram file URL directly
    $documentUrl = "https://t.me/ArtixKriegerCH/2063"; // Replace with your actual document link
    sendDocument($chatId, $documentUrl, "⬇️ Here is the APK Non-Root file.");
    } elseif ($data === 'add_balance') {
        $state[$chatId] = ['action' => 'add_balance'];
        saveJson($stateFile, $state);
        sendMessage($chatId, "Please send me the Telegram ID to add balance to:");
    } elseif ($data === 'broadcast') {
        $state[$chatId] = ['action' => 'broadcast'];
        saveJson($stateFile, $state);
        sendMessage($chatId, "Please send me the messages u want to broadcast");
    } elseif ($data === 'broadcast_R') {
        $state[$chatId] = ['action' => 'broadcast_R'];
        saveJson($stateFile, $state);
        sendMessage($chatId, "Please send me the messages u want to broadcast to Resellers");
    } elseif ($data === 'deduct_balance') {
        $state[$chatId] = ['action' => 'deduct_balance'];
        saveJson($stateFile, $state);
        sendMessage($chatId, "Please send me the Telegram ID to add balance to:");
    } elseif ($data === 'change_role') {
         deleteMessage($chatId, $messageId);
        showRoleSelectionMenu($chatId);
   } else if ($data === 'show_profile') {
        deleteMessage($chatId, $messageId);
        handleShowProfile($chatId); // Call the function to show the profile
   } elseif ($data === 'add_avatar') {
        deleteMessage($chatId, $messageId);
    $state[$chatId] = ['action' => 'avatar'];
    saveJson('state.json', $state);
    error_log("State updated for chat $chatId: " . json_encode($state)); 
    sendMessage($chatId, "📸 Please send your profile picture as an image file.");
    error_log("Message sent to chat $chatId: Awaiting avatar image.");
    
    } elseif ($data === 'add_description') {
         deleteMessage($chatId, $messageId);
        $state[$chatId] = ['action' => 'description'];
        saveJson('state.json', $state);
        error_log("State updated for chat $chatId: " . json_encode($state)); 
        sendMessage($chatId, "📝 Send me your Payment Methods (ex: Gcash) ");
        error_log("Message sent to chat $chatId: Awaiting description input.");
    } else if (strpos($data, 'social_') === 0) {
         deleteMessage($chatId, $messageId);
    $platform = str_replace('social_', '', $data);
    $state[$chatId] = ['action' => 'update_social', 'platform' => $platform];
    saveJson($stateFile, $state);
    sendMessage($chatId, "✍️ Now send me the URL for <b>$platform</b>:");
    } elseif ($data === 'add_social_links') {
     deleteMessage($chatId, $messageId);
    showSocialLinksMenu($chatId);
    } elseif ($data === 'balance_op') {
         deleteMessage($chatId, $messageId);
        BalanceOption($chatId);
    } elseif (strpos($data, 'role_') === 0) {
         deleteMessage($chatId, $messageId);
        $role = str_replace('role_', '', $data);
        $state[$chatId] = ['action' => 'change_role', 'role' => $role];
        saveJson($stateFile, $state);
        sendMessage($chatId, "Now send me the Telegram Username to assign the role <b>$role</b> to:");
    } elseif ($data === 'add_keys') {
         deleteMessage($chatId, $messageId);
        showAddKeysMenu($chatId);
    } elseif (strpos($data, 'add_key_') === 0) {
        $keyDuration = str_replace('add_key_', '', $data);
        $state[$chatId] = ['action' => 'add_keys', 'key_duration' => $keyDuration];
        saveJson($stateFile, $state);
        sendMessage($chatId, "Please send me the key to add for <b>{$keyDuration}</b>:");
    } elseif ($data === "deposit") {
         deleteMessage($chatId, $messageId);
        showDepositMenu($chatId, $messageId);
        deleteMessage($chatId, $messageId);
    } elseif ($data === "reseller_menu") {
         deleteMessage($chatId, $messageId);
        handleResellerMenu($chatId, $messageId);
        deleteMessage($chatId, $messageId);
    } elseif ($data === "/crypto") {
         deleteMessage($chatId, $messageId);
        showCryptoMenu($chatId, $messageId);
    }else if ($data === "/paypal") {
         deleteMessage($chatId, $messageId);
        showPayPalMenu($chatId, $messageId);
        showDepositMenu($chatId, $messageId);
    }else   if ($data === 'ban_user') {
        $state[$chatId] = ['action' => 'ban'];
        saveJson('state.json', $state);
        error_log("State updated for chat $chatId: " . json_encode($state));
        sendMessage($chatId, "🚫 Please send the <b>User ID</b> of the user you want to ban:");
        error_log("Message sent to chat $chatId: Ban action requested."); 
    }elseif ($data === 'unban_user') {
        $state[$chatId] = ['action' => 'unban'];
        saveJson('state.json', $state);
        error_log("State updated for chat $chatId: " . json_encode($state)); 
        sendMessage($chatId, "🔓 Please send the <b>User ID</b> of the user you want to unban:");
        error_log("Message sent to chat $chatId: Unban action requested.");
    }elseif ($data === 'deposit') {
         deleteMessage($chatId, $messageId);
        // Go back to main menu
        showMainMenu($chatId);
        deleteMessage($chatId, $messageId);
    }elseif ($data === "/back") {
        deleteMessage($chatId, $messageId);
          showUserMenu($chatId, isAdmin($userId, $roles));
          deleteMessage($chatId, $messageId);
    }elseif ($data === "main_menu") {
         deleteMessage($chatId, $messageId);
          showUserMenu($chatId, isAdmin($userId, $roles));
    }elseif ($data === 'download_data_json') {
         handleViewUsers($chatId);
         showAdminMenu($chatId);
    }elseif ($data === 'show_keys') {
         showKeys($chatId);
    }else if ($data == 'system_health') {
         systemHealthCheck($chatId);
    }else if ($data == 'ban_unban') {
         BanUnban($chatId);
    }else if ($data === 'joined') {
         handleJoinedCallback($callbackQueryId, $chatId, $userId, $roles);
         deleteMessage($chatId, $messageId);
    }else if ($data === 'reset_data') {
         deleteMessage($chatId, $messageId);
        handleResetData($chatId);
    } elseif ($data === 'start') {
         deleteMessage($chatId, $messageId);
        handleStart($chatId);
    
    }
}

// Step 5: Handling confirmation or cancellation
function handleDeposits($chatId, $text, &$state) {
    global $pdo;

    // Step 1: Validate the deposit amount
    $amount = (int)$text;

    if ($amount <= 0) {
        sendMessage($chatId, "❌ Invalid amount. Please enter a positive number.");
        return;
    }

    // Step 2: Fetch user details based on the id
    $sql = "SELECT id, username FROM Gay WHERE id = :userId";  // Use 'id' instead of 'chat_id'
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':userId', $chatId, PDO::PARAM_INT); // Bind the chatId to the userId field
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Step 3: Ask for confirmation
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ Yes', 'callback_data' => 'confirm_deposit_yes']],
                [['text' => '❌ No', 'callback_data' => 'confirm_deposit_no']]
            ]
        ];

       sendMessage(
    $chatId,
    "✨ <b>Deposit Confirmation</b> ✨\n\n" .
    "🔹 <b>Telegram ID:</b> <code>{$user['id']}</code>\n" .
    "🔹 <b>Username:</b> <i>{$user['username']}</i>\n" .
    "🔹 <b>Deposit Amount:</b> <b>\$$amount</b>\n\n" .
    "💬 <i>Please review the details carefully:</i>\n\n" .
    "✅ <b>To confirm the deposit, press <b>'Yes'</b>.</b>\n" .
    "❌ <b>To cancel, press <b>'No'</b>.</b>\n\n" .
    "⚠️ <i>If you have any questions, feel free to contact support!</i>\n\n" .
    "📢 <b><u>Important Disclaimer:</u></b>\n" .
    "<i>All balances in this bot are non-refundable. Please make sure you are confident in your deposit before confirming. We cannot process any refund requests.</i>\n\n" .
    "🙅‍♂️ <i>Do not ask for a refund once the transaction is completed. Thank you for your understanding!</i>",
    $keyboard
);

        // Step 4: Store the deposit amount in the state for later use
        $state[$chatId]['deposit_amount'] = $amount;
        saveJson("state.json", $state);
    } else {
        sendMessage($chatId, "❌ User not found. Please try again.");
    }
}

function handleDepositConfirmation($chatId, $callbackData, &$state) {
    global $pdo;

    // Assuming deposit amount and target user id are already in the state
    $depositAmount = $state[$chatId]['deposit_amount'];
    $userId = $state[$chatId]['target_id'];

    // Fetch username from the database if it's not already in the state
    $username = $state[$chatId]['username'] ?? '';
    if (empty($username)) {
        $sql = "SELECT username FROM Gay WHERE id = :userId";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':userId' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $username = $user['username'];
            // Optionally, store username in state for future use
            $state[$chatId]['username'] = $username;
            saveJson("state.json", $state); // Save state with username
        }
    }
    // Check if the deposit amount is set in the state
    if (!isset($state[$chatId]['deposit_amount'])) {
        sendMessage($chatId, "❌ No deposit is in progress. Please start the deposit process.");
        return;
    }

    // Retrieve the deposit amount from the state
    $amount = $state[$chatId]['deposit_amount'];

    if ($callbackData === 'confirm_deposit_yes') {
        // Step 6: Confirm the deposit and update the user's balance
        $sql = "SELECT balance FROM Gay WHERE id = :userId";  // Use 'id' instead of 'chat_id'
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':userId', $chatId, PDO::PARAM_INT);  // Bind the userId correctly
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Add the deposit amount to the user's current balance
            $newBalance = (float)$user['balance'] + $amount;  // Cast balance to float for proper addition

            // Update the balance in the database
            $sql = "UPDATE Gay SET balance = :balance WHERE id = :userId";  // Use 'id' instead of 'chat_id'
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':balance', $newBalance, PDO::PARAM_STR);  // Bind the balance as a string (varchar)
            $stmt->bindParam(':userId', $chatId, PDO::PARAM_INT);  // Bind the userId correctly
            $stmt->execute();

            // Clear the state and confirm the operation
            unset($state[$chatId]);
            saveJson("state.json", $state);  // Save the updated state

           sendMessage($chatId, 
        "🎉 <b>Deposit Successful!</b>\n\n" .
        "💸 Your deposit of <b>\$$depositAmount</b> has been successfully added to your account! 💳\n\n" .
        "🔄 <b>New Balance: \$$newBalance</b>\n\n" .
        "🙏 <i>Thank you for using our service! We appreciate your support! 🌟</i>\n\n" .
        "✨ Your balance is now ready for more purchases or transactions. Keep going and enjoy our features! 🔥\n\n" .
        "🔑 <b>Need anything else?</b> Feel free to check your balance again or make another deposit anytime. 💼💰\n\n" .
        "<i>Tip: You can always <b>check your balance</b> at any time by typing <b>/start</b>!</i>\n\n" .
        "✅ <b>If you need further assistance, don't hesitate to reach out to our support team!</b>\n\n" .
        "📝 <b>Deposit Amount: </b><code>\$$depositAmount</code>\n\n" .
        "📈 <b>New Balance: </b><code>\$$newBalance</code>\n\n" .
        "💬 <i>If you'd like to make another deposit or see your recent transactions, just let us know!</i>"
    );

        } else {
            sendMessage($chatId, "❌ User not found in the database.");
        }
    } elseif ($callbackData === 'confirm_deposit_no') {
        // Step 7: Cancel the deposit process
        unset($state[$chatId]);  // Clear the ongoing deposit state
        saveJson("state.json", $state);  // Save the updated state
 sendMessage($chatId, "❌ <b>Deposit Canceled</b>\n\nYour deposit of <b>\$$amount</b> has been <i>canceled</i>.\n\nIf you change your mind, you can always try again later. 😊");
    }
}


// Handle messages
function processMessage($chatId, $text, &$roles, &$state, $input, $photo) {
    global $rolesFile, $stateFile, $keysFile;

    $stateFile = 'state.json';
    $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

    if (isset($state[$chatId])) {
        $currentAction = $state[$chatId]['action'] ?? null;

        if ($currentAction === 'add_balance') {
            handleAddBalance($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'deduct_balance') {
            handleDeductBalance($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'broadcast') {
            Megaphone($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'broadcast_R') {
            Megaphone2($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'change_role') {
            handleChangeRole($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'add_keys') {
            handleAddKeys($chatId, $text, $state, $keysFile);
        } elseif ($currentAction === 'paypal_custom') {
            handleDeposits($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'crypto_custom') {
            processCryptoDeposit($chatId, $text, $state, $roles);
        } elseif ($currentAction === 'ban') { 
            processBanUnban($chatId, $text, $state); 
        } elseif ($currentAction === 'unban') {
            processBanUnban($chatId, $text, $state);
        } elseif ($currentAction === 'update_social') {
            
            $platform = $state[$chatId]['platform'] ?? null; // Get platform from state
            if ($platform) {
                processUserMessage($chatId, $text, $stateFile); // Pass the URL as $text
            } else {
                sendMessage($chatId, "⚠️ Unable to update social link. Please try again.");
            }
        } elseif ($currentAction === 'avatar') {
            handleAvatar($chatId, $photo, $state, $input); // Process the image 
        } elseif ($currentAction === 'description') {
            handleDescription($chatId, $text); // Process the description text
        }
    } else {
        if ($text === "/view_users") {
            if (isAdmin($chatId, $roles)) {
                sendFile($chatId, $rolesFile, "📋 Here is the user data");
            } else {
                sendMessage($chatId, "❌ You don't have permission to view this.");
            }
        } else {
            sendMessage($chatId, "Unknown command. Type /start to start the bot!");
        }
    }
}

function autoCheckAndUpdateRoles() {
    global $pdo;

    error_log("Starting autoCheckAndUpdateRoles function"); // Debug

    try {
        // Fetch all users with the role 'resellers'
        $query = $pdo->prepare("SELECT * FROM Gay WHERE role = 'resellers'");
        $query->execute();
        $users = $query->fetchAll(PDO::FETCH_ASSOC);

        if (!$users) {
            error_log("No resellers found to process."); // Debug
            return;
        }

        error_log("Found " . count($users) . " resellers to process."); // Debug

        foreach ($users as $user) {
            $username = $user['username'];
            $balance = floatval($user['balance']);

            error_log("Processing reseller: $username with balance: $balance"); // Debug

            // Check if balance is less than 1
            if ($balance < 2) {
                // Update role to 'user'
                $updateQuery = $pdo->prepare("UPDATE Gay SET role = 'user' WHERE username = :username");
                $updateQuery->execute([':username' => $username]);

                if ($updateQuery->rowCount() > 0) {
                    error_log("Role updated to 'user' for: $username"); // Debug
                } else {
                    error_log("Failed to update role for: $username"); // Debug
                }

                // Call DELETE endpoint to remove reseller data
                if (callDeleteEndpoint($username)) {
                    error_log("Successfully removed reseller data for: $username");
                } else {
                    error_log("Failed to remove reseller data for: $username");
                }
            } else {
                error_log("Reseller $username has sufficient balance ($balance), no action taken.");
            }
        }
    } catch (Exception $e) {
        error_log("Error in autoCheckAndUpdateRoles: " . $e->getMessage());
    }
}


function callDeleteEndpoint($username) {
    global $botToken; // Use the global botToken for verification

    // Remove '@' if it exists at the start of the username
    $cleanUsername = ltrim($username, '@');
    $url = "https://storage.masyvictory.me/api/resellers?resellerId=$cleanUsername";

    error_log("Preparing DELETE request for resellerId: $cleanUsername"); // Debug

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set a timeout
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $botToken", // Add token for verification
        "Content-Type: application/json",
        "Accept: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        error_log("DELETE request successful for resellerId: $cleanUsername (HTTP code: $httpCode)");
        return true;
    } else {
        error_log("DELETE request failed for resellerId: $cleanUsername (HTTP code: $httpCode, error: $error, response: $response)");
        return false;
    }
}


//sending json 
function sendResellerData($botToken) {
    $directory = __DIR__ . '/'; // Directory containing reseller JSON files
    $apiUrl = "https://storage.masyvictory.me/api/resellers";
    $files = glob($directory . 'resellers_data*.json'); // Find all reseller files

    if (empty($files)) {
        error_log("[ERROR] No reseller data files found in the directory: $directory");
        return false;
    }

    foreach ($files as $filePath) {
        if (!file_exists($filePath)) {
            error_log("[ERROR] File not found: $filePath");
            continue; // Skip missing files
        }

        $resellerData = json_decode(file_get_contents($filePath), true);

        if (empty($resellerData)) {
            error_log("[ERROR] Reseller data is empty or invalid in file: $filePath");
            continue; // Skip invalid or empty data
        }

        // Prepare POST data
        $postData = json_encode($resellerData);

        // Check for JSON encoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[ERROR] Failed to encode JSON data for file: $filePath. Error: " . json_last_error_msg());
            continue;
        }

        // Log the exact payload
        error_log("[DEBUG] Sending POST request to $apiUrl with data from file: $filePath");

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer $botToken"
            ]
        ]);

        // Execute cURL request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Check for cURL errors
        if (curl_errno($ch)) {
            error_log("[ERROR] cURL error while sending file: $filePath. Error: " . curl_error($ch));
            curl_close($ch);
            continue; // Skip this file
        }

        // Close cURL handle
        curl_close($ch);

        // Log the HTTP response
        error_log("[DEBUG] HTTP Response Code: $httpCode for file: $filePath. Response: $response");

        // Check HTTP response code
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("[SUCCESS] Reseller data successfully sent for file: $filePath");
        } else {
            error_log("[ERROR] Failed to send reseller data for file: $filePath. HTTP Code: $httpCode. Response: $response");

            // Decode the response for debugging
            $responseArray = json_decode($response, true);
            if ($responseArray && isset($responseArray['error'])) {
                error_log("[ERROR] API Error for file: $filePath. Error: " . $responseArray['error']);
            }
        }
    }

    return true;
}

function processUpdate($update) {
        global $rolesFile, $stateFile, $pdo, $adminChatId, $botToken;
        if (isset($update['message']['photo'])) {
            $photo = $update['message']['photo']; // Set photo array
        } else {
            $photo = null; // Ensure it's defined
        }
        $username = $update['message']['from']['username'] ?? "unknown";
        if (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
         } elseif (isset($update['message'])) {
        $chatId = $update['message']['chat']['id'];
        $userId = $update['message']['from']['id'];
        $text = isset($update['message']['text']) ? sanitizeInput($update['message']['text']) : null;
        $roles = loadJson($rolesFile);
        $state = loadJson($stateFile);
        $username = $update['message']['from']['username'] ?? "unknown"; // 
        $input = isset($message['text']) ? $message['text'] : ''; 
        if (isFlooding($chatId)) {
        sendMessage($chatId, "❌ You are sending too many requests. Please slow down.");
        error_log("Flooding detected for user: $chatId"); // Debug log
        return;
        }
        if (!isset($adminChatId)) {
            $adminChatId = '1058646211'; // Replace with the actual admin chat ID
        }


        $sql = "SELECT banned FROM Gay WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $chatId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
     
        if ($user && $user['banned'] == 1) {
            // Notify banned user
            sendMessage($chatId, "❌ You are banned from using this bot.");
            error_log("Blocked /start command for banned user: $chatId"); // Debug log
            return; // Stop further processing
        }
        if ($text === "/start") {
        
            saveUserDetails($chatId, $username, $roles); // Save user details with the correct username
            $channelId = '@ArtixKriegerCH'; // Replace with your channel username

            // Check if the user is a member of the channel
            if (checkChannelMembership($chatId, $userId, $channelId)) {
                // If the user is a member, show the main menu
                showUserMenu($chatId, isAdmin($userId, $roles));
            } else {
                // If the user is not a member, prompt them to join the channel
                handleStartCommand($chatId);
            }
        } else {
            processMessage($chatId, $text, $roles, $state, $input, $photo);
        }
    }
}


// Main logic
$update = json_decode(file_get_contents("php://input"), true);
if ($update) {
    processUpdate($update);
}
?>
