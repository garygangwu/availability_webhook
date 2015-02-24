<?php

define('__ROOT__', dirname(dirname(__FILE__))); 
require_once(__ROOT__.'/lib/utils.php'); 

// Web hook actions
define("DUMP", "dump");
define("CHECK", "check_reservable");
define("RESERVE", "make_reservation");
define("UPDATE", "update_reservation");
define("CANCEL", "cancel_reservation");
define("SECONDS_PER_DAY", 86400);

// DB related
define("DB_HOST", "localhost");
define("DB_USER_NAME", "airbnb_admin");
define("DB_PASSWORD", "123456");
define("DB_NAME", "airbnb");

// Table structure:
//  +----------------+-------------+------+-----+---------+-------+
//  | Field          | Type        | Null | Key | Default | Extra |
//  +----------------+-------------+------+-----+---------+-------+
//  | listing_id     | int(11)     | NO   | PRI | NULL    |       |
//  | busy_date      | varchar(16) | NO   | PRI | NULL    |       |
//  | code           | varchar(16) | NO   |     | NULL    |       |
//  +----------------+-------------+------+-----+---------+-------+

//
// Read the input parameters
//

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
  $post_body = json_decode(file_get_contents('php://input', 'r'), true);
} else {
  $post_body = array();
}

$listing_id = (int)idx($_REQUEST, $post_body, 'listing_id');
$check_in = idx($_REQUEST, $post_body, 'start_date');
$nights = (int)idx($_REQUEST, $post_body, 'nights');
$code = idx($_REQUEST, $post_body, 'code');

$check_in_time = strtotime($check_in);
$check_out_time = $check_in_time + $nights * SECONDS_PER_DAY;
$check_out = date('Y-m-d', $check_out_time);

$action = 'undefined';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $action = RESERVE;
} else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
  $action = CANCEL;
} else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
  $action = UPDATE;
} else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
  if (!empty($listing_id)) {
    $action = CHECK;
  } else {
    $action = DUMP;
  }
}

// headers for not caching the results
header('Cache-Control: no-cache, must-revalidate');

// headers to tell that result is JSON
header('Content-type: application/json');

try {
  sanity_check_input($action, $listing_id, $check_in, $check_out, $check_in_time, $check_out_time, $code);
} catch (Exception $e) {
  http_response_code(400);
  render_output(array('error_type' => 'bad_input', 'error_message' => $e->getMessage()));
  return;
}

//
// Process
//

try {
  switch ($action) {
    case DUMP:
      $result = dump($check_in_time, $check_out_time);
      break;
    case RESERVE:
      $result = reserve($listing_id, $code, $check_in_time, $check_out_time);
      break;
    case CANCEL:
      $result = cancel($code);
      break;
    case UPDATE:
      $result = update($listing_id, $code, $check_in_time, $check_out_time);
      break;
    case CHECK:
      $result = check($listing_id, $check_in_time, $check_out_time);
      break;
  }
  render_output($result);
} catch (InvalidArgumentException $e) {
  http_response_code(400);
  render_output(array('error_type' => 'bad_input', 'error_message' => $e->getMessage()));
} catch (RuntimeException $e) {
  http_response_code(404);
  render_output(array('error_type' => 'not_found', 'error_message' => $e->getMessage()));
} catch (Exception $e) {
  http_response_code(500);
  render_output(array('error_type' => 'query_failed', 'error_message' => $e->getMessage()));
}

//
// Functions
//

function dump($check_in_time, $check_out_time) {
  $conn = open_db_conn();
  $check_in = date('Y-m-d', $check_in_time);
  $check_out = date('Y-m-d', $check_out_time);
  $statement = "SELECT listing_id, busy_date, code FROM reservations " .
               "WHERE busy_date >= '{$check_in}' AND busy_date < '{$check_out}';";
  $result = mysql_query($statement, $conn);
  if (!$result) {
    throw new Exception("DB query failed");
  }
  $ret = array();
  while ($row = mysql_fetch_assoc($result)) {
    $ret[] = array(
      'listing_id' => $row['listing_id'],
      'date' => $row['busy_date'],
      'code' => $row['code']
    );
  }
  return $ret;
}

function check($listing_id, $check_in_time, $check_out_time) {
  $conn = open_db_conn();
  $check_in = date('Y-m-d', $check_in_time);
  $check_out = date('Y-m-d', $check_out_time);
  $statement = "SELECT COUNT(1) as cnt FROM reservations " .
               "WHERE listing_id = '{$listing_id}' AND busy_date >= '{$check_in}' AND busy_date < '{$check_out}';";
  $result = mysql_query($statement, $conn);
  if (!$result) {
    throw new Exception("DB query failed");
  }
  $row = mysql_fetch_assoc($result);
  $count = intval($row['cnt']);

  return array(
    "available" => $count == 0 ? TRUE : FALSE,
    "listing_id" => $listing_id,
    "check_in" => $check_in, 
    "check_out" => $check_out,
  );
}

function cancel($code) {
  $conn = open_db_conn();
  $statement = "DELETE FROM reservations " .
               "WHERE code='{$code}';";
  $result = mysql_query($statement, $conn);

  if (!$result) {
    throw new Exception("DB query failed");
  }

  $succeed = mysql_affected_rows() > 0;

  if (!$succeed) {
    http_response_code(404);
  }

  return array(
    "succeed" => $succeed
  );
}

function reserve($listing_id, $code, $check_in_time, $check_out_time) {
  $conn = open_db_conn();
  
  $statement = 'INSERT INTO reservations (listing_id, busy_date, code) VALUES ';

  $values_to_update = array();
  $cur_time = $check_in_time;
  while ($cur_time < $check_out_time) {
    $busy_date = date('Y-m-d', $cur_time);
    $values_to_update[] = "({$listing_id}, '{$busy_date}', '{$code}')";
    $cur_time += SECONDS_PER_DAY;
  }
  $statement = $statement . implode(',', $values_to_update) . ';';

  $result = mysql_query($statement, $conn);

  return array(
    "succeed" => $result? true : false,
    "listing_id" => $listing_id,
    "code" => $code,
    "check_in" => date('Y-m-d', $check_in_time),
    "check_out" => date('Y-m-d', $check_out_time),
  );
}

function update($listing_id, $code, $check_in_time, $check_out_time) {
  $conn = open_db_conn();
  // Ensure the input $listing_id $code matches to the existing records
  $statement = "SELECT listing_id " .
               "FROM reservations WHERE code = '{$code}';";
  $result = mysql_query($statement, $conn);
  if (!$result) {
    throw new Exception("DB query failed");
  }
  $row = mysql_fetch_assoc($result);
  if (!$row || is_null($row['listing_id'])) {
    throw new RuntimeException("Reservation id, $code, cannot be found");
  }
  $db_listing_id = (int)$row['listing_id'];
  if ($listing_id != $db_listing_id) {
    throw new InvalidArgumentException(
      "Listing $listing_id doesn't match to the reservation record");
  }

  try {
    mysql_query("START TRANSACTION", $conn);
    mysql_query("BEGIN", $conn);

    $delete_statement = "DELETE FROM reservations " .
                        "WHERE code='{$code}';";
    if (!mysql_query($delete_statement, $conn)) {
      throw new Exception("Failed to remove the old reservation");
    }

    // Remove old records and then insert new records
    $insert_statement = 'INSERT INTO reservations (listing_id, busy_date, code) VALUES ';
    $values_to_update = array();
    $cur_time = $check_in_time;
    while ($cur_time < $check_out_time) {
      $busy_date = date('Y-m-d', $cur_time);
      $values_to_update[] = "({$listing_id}, '{$busy_date}', '{$code}')";
      $cur_time += SECONDS_PER_DAY;
    }
    $insert_statement .= implode(',', $values_to_update) . ';';
    if (!mysql_query($insert_statement, $conn)) {
      throw new Exception("Failed to insert the new reservation");
    }

    mysql_query("COMMIT", $conn);
  } catch (Exception $e) {
    mysql_query("ROLLBACK", $conn);
    throw new Exception($e->getMessage());
  }

  return array(
    "succeed" => true,
    "listing_id" => $listing_id,
    "code" => $code,
    "check_in" => date('Y-m-d', $check_in_time),
    "check_out" => date('Y-m-d', $check_out_time),
  );
}

function open_db_conn() {
  $conn = mysql_connect(DB_HOST, DB_USER_NAME, DB_PASSWORD);
  if (!$conn || !mysql_select_db(DB_NAME, $conn)) {
    throw new Exception("DB open failed");
  }
  return $conn;
}

function sanity_check_input($action, $listing_id, $check_in, $check_out, $check_in_time, $check_out_time, $code) {
  if ((is_null($check_in) || is_null($check_out)) && $action != CANCEL) {
    throw new Exception("missing check_in or check_out date");
  }

  if (empty($listing_id) && ($action == UPDATE || $action == RESERVE || $action == CHECK)) {
    throw new Exception("listing_id missing");
  }

  if (empty($code) && ($action == UPDATE || $action == RESERVE || $action == CANCEL)) {
    throw new Exception("code missing");
  }

  $now = time();
  if ($check_in_time <= $now - SECONDS_PER_DAY && $action != CANCEL) {
    throw new Exception("check_in is a past date");
  }

  if ($check_in_time >= $check_out_time && $action != CANCEL) {
    throw new Exception("check_out date is before or equal to the check_in date");
  }

  if ($check_out_time > $now + 365 * SECONDS_PER_DAY) {
    throw new Exception("out of supported check out date range");
  }

  $permit_actions = array(DUMP, CHECK, UPDATE, RESERVE, CANCEL);
  if (!in_array($action, $permit_actions)) {
    throw new Exception("Unsupported action");
  }
}

function idx($array1, $array2, $key) {
  if (isset($array1[$key])) {
    return $array1[$key];
  }
  if (isset($array2[$key])) {
    return $array2[$key];
  }
  return null;
}

function render_output($data) {
  echo json_encode($data);
}

?>
