<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';

require __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
require __DIR__ . '/../../libs/PHPMailer/SMTP.php';
require __DIR__ . '/../../libs/PHPMailer/Exception.php';

require 'settimezone.php';

// Get all user ids
$query = "SELECT id, username FROM user";
$stmt = $db->prepare($query);
$usersToNotify = $stmt->execute();

while ($userToNotify = $usersToNotify->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userToNotify['id'];
    if (php_sapi_name() !== 'cli') {
        echo "For user: " . $userToNotify['username'] . "<br />";
    }

    $emailNotificationsEnabled = false;
    $gotifyNotificationsEnabled = false;
    $telegramNotificationsEnabled = false;
    $pushoverNotificationsEnabled = false;
    $discordNotificationsEnabled = false;
    $ntfyNotificationsEnabled = false;
    $webhookNotificationsEnabled = false;

    // Check if email notifications are enabled and get the settings
    $query = "SELECT * FROM email_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $emailNotificationsEnabled = $row['enabled'];
        $email['smtpAddress'] = $row["smtp_address"];
        $email['smtpPort'] = $row["smtp_port"];
        $email['encryption'] = $row["encryption"];
        $email['smtpUsername'] = $row["smtp_username"];
        $email['smtpPassword'] = $row["smtp_password"];
        $email['fromEmail'] = $row["from_email"] ? $row["from_email"] : "wallos@wallosapp.com";
        $email['otherEmails'] = $row["other_emails"];
    }

    // Check if Discord notifications are enabled and get the settings
    $query = "SELECT * FROM discord_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $discordNotificationsEnabled = $row['enabled'];
        $discord['webhook_url'] = $row["webhook_url"];
        $discord['bot_username'] = $row["bot_username"];
        $discord['bot_avatar_url'] = $row["bot_avatar_url"];
    }

    // Check if Gotify notifications are enabled and get the settings
    $query = "SELECT * FROM gotify_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $gotify = [];

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $gotifyNotificationsEnabled = $row['enabled'];
        $gotify['serverUrl'] = $row["url"];
        $gotify['appToken'] = $row["token"];
        $gotify['ignore_ssl'] = $row["ignore_ssl"];
    }

    // Check if Telegram notifications are enabled and get the settings
    $query = "SELECT * FROM telegram_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $telegramNotificationsEnabled = $row['enabled'];
        $telegram['botToken'] = $row["bot_token"];
        $telegram['chatId'] = $row["chat_id"];
    }

    // Check if Pushover notifications are enabled and get the settings
    $query = "SELECT * FROM pushover_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $pushoverNotificationsEnabled = $row['enabled'];
        $pushover['user_key'] = $row["user_key"];
        $pushover['token'] = $row["token"];
    }

    // Check if Ntfy notifications are enabled and get the settings
    $query = "SELECT * FROM ntfy_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ntfyNotificationsEnabled = $row['enabled'];
        $ntfy['host'] = $row["host"];
        $ntfy['topic'] = $row["topic"];
        $ntfy['headers'] = $row["headers"];
        $ntfy['ignore_ssl'] = $row["ignore_ssl"];
    }

    // Check if webhook notifications are enabled and have cancelation payload set and get the settings
    $query = "SELECT * FROM webhook_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $webhook = [];
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $webhook['url'] = $row["url"];
        $webhook['headers'] = $row["headers"];
        $webhook['cancelation_payload'] = $row["cancelation_payload"];
        $webhook['ignore_ssl'] = $row["ignore_ssl"];
        $webhook['request_method'] = $row["request_method"];
        $webhookNotificationsEnabled = $row['enabled'] && $row['cancelation_payload'] != "";
    }

    $notificationsEnabled = $emailNotificationsEnabled || $gotifyNotificationsEnabled || $telegramNotificationsEnabled ||
        $pushoverNotificationsEnabled || $discordNotificationsEnabled ||$ntfyNotificationsEnabled || $webhookNotificationsEnabled;

    // If no notifications are enabled, no need to run
    if (!$notificationsEnabled) {
        if (php_sapi_name() !== 'cli') {
            echo "Notifications are disabled. No need to run.<br />";
        }
        continue;
    } else {
        // Get all currencies
        $currencies = array();
        $query = "SELECT * FROM currencies WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $currencies[$row['id']] = $row;
        }

        // Get all household members
        $query = "SELECT * FROM household WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $resultHousehold = $stmt->execute();

        $household = [];
        while ($rowHousehold = $resultHousehold->fetchArray(SQLITE3_ASSOC)) {
            $household[$rowHousehold['id']] = $rowHousehold;
        }

        // Get all categories
        $query = "SELECT * FROM categories WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $resultCategories = $stmt->execute();

        $categories = [];
        while ($rowCategory = $resultCategories->fetchArray(SQLITE3_ASSOC)) {
            $categories[$rowCategory['id']] = $rowCategory;
        }

        // Get current date to check which subscriptions are set to notify for cancellation
        $currentDate = new DateTime('now');
        $currentDate = $currentDate->format('Y-m-d');

        $query = "SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = :inactive AND cancellation_date = :cancellationDate ORDER BY payer_user_id ASC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':inactive', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':cancellationDate', $currentDate, SQLITE3_TEXT);
        $resultSubscriptions = $stmt->execute();

        $notify = [];
        $i = 0;
        $currentDate = new DateTime('now');
        while ($rowSubscription = $resultSubscriptions->fetchArray(SQLITE3_ASSOC)) {
            $notify[$rowSubscription['payer_user_id']][$i]['name'] = $rowSubscription['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['price'] = $rowSubscription['price'] . $currencies[$rowSubscription['currency_id']]['symbol'];
            $notify[$rowSubscription['payer_user_id']][$i]['currency'] = $currencies[$rowSubscription['currency_id']]['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['category'] = $categories[$rowSubscription['category_id']]['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['payer'] = $household[$rowSubscription['payer_user_id']]['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['date'] = $rowSubscription['next_payment'];
            $notify[$rowSubscription['payer_user_id']][$i]['url'] = $rowSubscription['url'];
            $notify[$rowSubscription['payer_user_id']][$i]['notes'] = $rowSubscription['notes'];
            $i++;
        }

        if (!empty($notify)) {

            // Email notifications if enabled
            if ($emailNotificationsEnabled) {

                $stmt = $db->prepare('SELECT * FROM user WHERE id = :user_id');
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $defaultUser = $result->fetchArray(SQLITE3_ASSOC);
                $defaultEmail = $defaultUser['email'];
                $defaultName = $defaultUser['username'];

                foreach ($notify as $userId => $perUser) {
                    $message = "The following subscriptions are up for cancellation:\n";

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] ."\n";
                    }

                    $smtpAuth = (isset($email["smtpUsername"]) && $email["smtpUsername"] != "") || (isset($email["smtpPassword"]) && $email["smtpPassword"] != "");

                    $mail = new PHPMailer(true);
                    $mail->CharSet = "UTF-8";
                    $mail->isSMTP();

                    $mail->Host = $email['smtpAddress'];
                    $mail->SMTPAuth = $smtpAuth;
                    if ($smtpAuth) {
                        $mail->Username = $email['smtpUsername'];
                        $mail->Password = $email['smtpPassword'];
                    }
                    if ($email['encryption'] != "none") {
                        $mail->SMTPSecure = $email['encryption'];
                    }
                    $mail->Port = $email['smtpPort'];

                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    $emailaddress = !empty($user['email']) ? $user['email'] : $defaultEmail;
                    $name = !empty($user['name']) ? $user['name'] : $defaultName;

                    $mail->setFrom($email['fromEmail'], 'Wallos App');
                    $mail->addAddress($emailaddress, $name);

                    if (!empty($email['otherEmails'])) {
                        $list = explode(';', $email['otherEmails']);

                        // Avoid duplicate emails
                        $list = array_unique($list);
                        $list = array_filter($list, function ($value) use ($emailaddress) {
                            return $value !== $emailaddress;
                        });

                        foreach($list as $value) {
                            $mail->addCC(trim($value));
                        }
                    }

                    $mail->Subject = 'Wallos Cancellation Notification';
                    $mail->Body = $message;

                    if ($mail->send()) {
                        echo "Email Notifications sent<br />";
                    } else {
                        echo "Error sending notifications: " . $mail->ErrorInfo . "<br />";
                    }
                }
            }

            // Discord notifications if enabled
            if ($discordNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    $title = translate('wallos_notification', $i18n);

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $postfields = [
                        'content' => $message
                    ];

                    if (!empty($discord['bot_username'])) {
                        $postfields['username'] = $discord['bot_username'];
                    }

                    if (!empty($discord['bot_avatar_url'])) {
                        $postfields['avatar_url'] = $discord['bot_avatar_url'];
                    }

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $discord['webhook_url']);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $response = curl_exec($ch);
                    curl_close($ch);

                    if ($result === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Discord Notifications sent<br />";
                    }
                }
            }

            // Gotify notifications if enabled
            if ($gotifyNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $data = array(
                        'message' => $message,
                        'priority' => 5
                    );

                    $data_string = json_encode($data);

                    $ch = curl_init($gotify['serverUrl'] . '/message?token=' . $gotify['appToken']);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        array(
                            'Content-Type: application/json',
                            'Content-Length: ' . strlen($data_string)
                        )
                    );

                    if ($gotify['ignore_ssl']) {
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    }

                    $result = curl_exec($ch);
                    if ($result === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Gotify Notifications sent<br />";
                    }
                }
            }

            // Telegram notifications if enabled
            if ($telegramNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $data = array(
                        'chat_id' => $telegram['chatId'],
                        'text' => $message
                    );

                    $data_string = json_encode($data);

                    $ch = curl_init('https://api.telegram.org/bot' . $telegram['botToken'] . '/sendMessage');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        array(
                            'Content-Type: application/json',
                            'Content-Length: ' . strlen($data_string)
                        )
                    );

                    $result = curl_exec($ch);
                    if ($result === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Telegram Notifications sent<br />";
                    }
                }
            }

            // Pushover notifications if enabled
            if ($pushoverNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, "https://api.pushover.net/1/messages.json");
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'token' => $pushover['token'],
                        'user' => $pushover['user_key'],
                        'message' => $message,
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $result = curl_exec($ch);

                    curl_close($ch);

                    if ($result === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Pushover Notifications sent<br />";
                    }
                }
            }

            // Ntfy notifications if enabled
            if ($ntfyNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $headers = json_decode($ntfy["headers"], true);
                    $customheaders = array_map(function ($key, $value) {
                        return "$key: $value";
                    }, array_keys($headers), $headers);

                    $ch = curl_init();

                    $ntfyHost = rtrim($ntfy["host"], '/');
                    $ntfyTopic = $ntfy['topic'];

                    curl_setopt($ch, CURLOPT_URL, $ntfyHost . '/' . $ntfyTopic);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    if ($ntfy['ignore_ssl']) {
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    }

                    $response = curl_exec($ch);
                    curl_close($ch);

                    if ($response === false) {
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        echo "Ntfy Notifications sent<br />";
                    }
                }
            }

            // Webhook notifications if enabled
            if ($webhookNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    $stmt = $db->prepare('SELECT * FROM household WHERE id = :userId');
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $user = $result->fetchArray(SQLITE3_ASSOC);
            
                    if ($user['name']) {
                        $payer = $user['name'];
                    }
            
                    foreach ($perUser as $subscription) {
                        // Ensure the payload is reset for each subscription
                        $payload = $webhook['cancelation_payload'];
                        $payload = str_replace("{{subscription_name}}", $subscription['name'], $payload);
                        $payload = str_replace("{{subscription_price}}", $subscription['price'], $payload);
                        $payload = str_replace("{{subscription_currency}}", $subscription['currency'], $payload);
                        $payload = str_replace("{{subscription_category}}", $subscription['category'], $payload);
                        $payload = str_replace("{{subscription_payer}}", $payer, $payload);
                        $payload = str_replace("{{subscription_date}}", $subscription['date'], $payload);
                        $payload = str_replace("{{subscription_url}}", $subscription['url'], $payload);
                        $payload = str_replace("{{subscription_notes}}", $subscription['notes'], $payload);
            
                        // Initialize cURL for each subscription
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $webhook['url']);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $webhook['request_method']);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            
                        // Add headers if they exist
                        if (!empty($webhook['headers'])) {
                            $customheaders = preg_split("/\r\n|\n|\r/", $webhook['headers']);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
                        }
            
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
                        // Handle SSL settings
                        if ($webhook['ignore_ssl']) {
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        }
            
                        // Execute the cURL request
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
            
                        if ($response === false || $httpCode >= 400) {
                            echo "Error sending cancellation notifications: " . curl_error($ch) . "<br />";
                        } else {
                            echo "Webhook Cancellation Notification sent for subscription: " . $subscription['name'] . "<br />";
                        }
            
                        usleep(1000000); // 1s delay between requests
                    }
                }
            }
            

        } else {
            if (php_sapi_name() !== 'cli') {
                echo "Nothing to notify.<br />";
            }
        }

    }

}

?>
