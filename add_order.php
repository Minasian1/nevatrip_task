<?php

$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'ticketing';

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


/**
 * @throws Exception
 */
function addOrder($conn, $event_id, $event_date, $ticket_adult_price, $ticket_adult_quantity, $ticket_kid_price, $ticket_kid_quantity, $preferential_price, $preferential_quantity, $user_id): void
{
    do {
        $barcode = rand(1000000000, 9999999999);;

        $response = checkBarcode($barcode, "orders");

        // If the barcode already exists, regenerate and retry
    } while (isset($response['error']) && $response['error'] === 'barcode already exists');

    // Check if booking was successful
    if (isset($response['message']) && $response['message'] === 'order successfully booked') {
        $confirmationResponse = approveOrder($barcode);

        if (isset($confirmationResponse['message']) && $confirmationResponse['message'] === 'order successfully approved') {

            $equal_price = ($ticket_adult_price * $ticket_adult_quantity) + ($ticket_kid_price * $ticket_kid_quantity) + ($preferential_price * $preferential_quantity);

            $sql = "INSERT INTO `orders` (event_id, event_date, ticket_adult_price, ticket_adult_quantity, ticket_kid_price, ticket_kid_quantity, preferential_price, preferential_quantity, barcode, equal_price, user_id, created) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("isiiiiiiiii", $event_id, $event_date, $ticket_adult_price, $ticket_adult_quantity, $ticket_kid_price, $ticket_kid_quantity, $preferential_price, $preferential_quantity, $barcode, $equal_price, $user_id);
                if ($stmt->execute()) {
                    addTicket($barcode);
                    echo "Order added successfully!";
                } else {
                    echo "Error adding order: " . $stmt->error;
                }
                $stmt->close();

            } else {
                echo "Prepare failed: " . $conn->error;
            }
        } else {
            echo "Approval failed: " . $confirmationResponse['error'];
        }
    } else {
        echo "Booking failed: " . $response['error'];
    }
}


function addTicket($parent_barcode): void
{
    global $conn;
    $sql = $conn->query("SELECT * FROM `orders` WHERE `barcode`='$parent_barcode'");
    $row = $sql->fetch_assoc();
    $ticket_count = ($row["ticket_adult_quantity"] + $row["ticket_kid_quantity"] + $row["preferential_quantity"]);
    $is_group = $ticket_count > 1;
    for ($i = 0; $i < $row['ticket_adult_quantity']; $i++) {
        do {
            $ticket_barcode = rand(1000000000, 9999999999);
            if (!$is_group) {
                $ticket_barcode = $parent_barcode;
            }
            $response = checkBarcode($ticket_barcode, "tickets");

        } while (isset($response['error']) && $response['error'] === 'barcode already exists');
        $type = "adult";
        $ticket_sql = "INSERT INTO `tickets` (barcode, parent_barcode, is_group, type) VALUES (?, ?, ?, ?)";
        $ticket_stmt = $conn->prepare($ticket_sql);
        $ticket_stmt->bind_param("ssis",  $ticket_barcode, $parent_barcode, $is_group, $type);
        $ticket_stmt->execute();
        $ticket_stmt->close();
    }


    for ($i = 0; $i < $row['ticket_kid_quantity']; $i++) {
        do {
            $ticket_barcode = rand(1000000000, 9999999999);
            $response = checkBarcode($ticket_barcode, "tickets");

        } while (isset($response['error']) && $response['error'] === 'barcode already exists');
        $type = "kid";
        $ticket_sql = "INSERT INTO `tickets` (barcode, parent_barcode, is_group, type) VALUES (?, ?, ?, ?)";
        $ticket_stmt = $conn->prepare($ticket_sql);
        $ticket_stmt->bind_param("ssis",  $ticket_barcode, $parent_barcode, $is_group, $type);
        $ticket_stmt->execute();
        $ticket_stmt->close();
    }


    for ($i = 0; $i < $row['preferential_quantity']; $i++) {
        do {
            $ticket_barcode = rand(1000000000, 9999999999);
            $response = checkBarcode($ticket_barcode, "tickets");

        } while (isset($response['error']) && $response['error'] === 'barcode already exists');
        $type = "preferential";
        $ticket_sql = "INSERT INTO `tickets` (barcode, parent_barcode, is_group, type) VALUES (?, ?, ?, ?)";
        $ticket_stmt = $conn->prepare($ticket_sql);
        $ticket_stmt->bind_param("ssis",  $ticket_barcode, $parent_barcode, $is_group, $type);
        $ticket_stmt->execute();
        $ticket_stmt->close();
    }
}

//// Sample function for approving an order with a third-party API
//function approveOrder($barcode): array
//{
//    $url = 'https://api.site.com/approve'; // The API endpoint for approval
//    $ch = curl_init($url);
//
//    // Set the request method to POST and pass the barcode as JSON
//    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//    curl_setopt($ch, CURLOPT_POST, true);
//    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['barcode' => $barcode]));
//    curl_setopt($ch, CURLOPT_HTTPHEADER, [
//        'Content-Type: application/json',
//    ]);
//
//    $response = curl_exec($ch);
//
//    if (curl_errno($ch)) {
//        return ['error' => curl_error($ch)];
//    }
//    curl_close($ch);
//
//    // Decode the response
//    $decodedResponse = json_decode($response, true);
//
//    if (isset($decodedResponse['message']) && $decodedResponse['message'] === 'order successfully approved') {
//        return ['message' => 'order successfully approved'];
//    } elseif (isset($decodedResponse['error'])) {
//        return ['error' => $decodedResponse['error']];
//    }
//
//    return ['error' => 'Unknown error occurred'];
//}

function checkBarcode($barcode_to_check, $table_name): array
{
$query_text ="SELECT * FROM `$table_name` WHERE `barcode`='$barcode_to_check'";
    global $conn;
    $check = $conn->query($query_text);
    if ($check->num_rows > 0) {
        return  ['error' => 'barcode already exists'];

    }
    else{
        return ['message' => 'order successfully booked'];

    }
}
function getErrorMessage(): string {
    $errorMessages = [
        'event cancelled',
        'no tickets',
        'no seats',
        'fan removed'
    ];
     return $errorMessages[array_rand($errorMessages)];
}

function approveOrder($barcode): array
{
    // Simulate random response
    $responses = [
        ['message' => 'order successfully approved'],
        ['error' => getErrorMessage()]
    ];
    return $responses[array_rand($responses)];
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $event_id = $_POST['event_id'];
    $event_date = $_POST['event_date'];
    $ticket_adult_price = $_POST['ticket_adult_price'];
    $ticket_adult_quantity = $_POST['ticket_adult_quantity'];
    $ticket_kid_price = $_POST['ticket_kid_price'];
    $ticket_kid_quantity = $_POST['ticket_kid_quantity'];
    $preferential_price = $_POST['preferential_price'];
    $preferential_quantity = $_POST['preferential_quantity'];
    $user_id = $_POST['user_id'];


    addOrder($conn, $event_id, $event_date, $ticket_adult_price, $ticket_adult_quantity, $ticket_kid_price, $ticket_kid_quantity, $preferential_price, $preferential_quantity, $user_id);


    $conn->close();

}


