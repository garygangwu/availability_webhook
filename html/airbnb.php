<?php

// Web hook actions
define("DUMP", "dump");
define("CHECK", "check");
define("TAKE", "take");
define("FREE", "free");
define("SECONDS_PER_DAY", 86400);

// DB related
define("DB_HOST", "localhost");
define("DB_USER_NAME", "airbnb_admin");
define("DB_PASSWORD", "123456");
define("DB_NAME", "airbnb");

// Table structure:
//  +------------+-------------+------+-----+-----------+-------+
//  | Field      | Type        | Null | Key | Default   | Extra |
//  +------------+-------------+------+-----+-----------+-------+
//  | listing_id | varchar(20) | NO   |     |           |       |
//  | busy_date  | varchar(20) | NO   | PRI |           |       |
//  | code       | varchar(64) | NO   |     | fake_code |       |
//  +------------+-------------+------+-----+-----------+-------+

$acceptable_actions = array(DUMP, CHECK, TAKE, FREE);

$listing_id = idx($_GET, 'listing_id');
$check_in = idx($_GET, 'check_in');
$check_out = idx($_GET, 'check_out');
$action = strtolower(idx($_GET, 'action'));
$code = idx($_GET, 'code');

$check_in_time = strtotime($check_in);
$check_out_time = strtotime($check_out);
$now = time();

// headers for not caching the results
header('Cache-Control: no-cache, must-revalidate');

// headers to tell that result is JSON
header('Content-type: application/json');

if (is_null($listing_id) && ($action == TAKE || $action == FREE) ||
    is_null($code) && ($action == TAKE || $action == FREE) ||
    $check_in_time <= $now ||
    $check_in_time >= $check_out_time ||
    $check_out_time > $now + 365 * SECONDS_PER_DAY ||
    !in_array($action, $acceptable_actions)) {
  render_output(array('error' => 'bad_input'));
  return;
}

try {
  switch ($action) {
    case DUMP:
      $result = dump($check_in_time, $check_out_time);
      break;
    case TAKE:
      $result = take($listing_id, $code, $check_in_time, $check_out_time);
      break;
    case FREE:
      $result = free($listing_id, $code, $check_in_time, $check_out_time);
      break;
    case CHECK:
      $result = check($check_in_time, $check_out_time);
      break;
  }
} catch (Exception $e) {
  $error_result = array('error' => 'query_failed', 'error_message' => $e->getMessage());
  render_output($error_result);
  return;
}

render_output($result);


function dump($check_in_time, $check_out_time) {
  $conn = open_db_conn();
  $check_in = date('Y-m-d', $check_in_time);
  $check_out = date('Y-m-d', $check_out_time);
  $statement = "SELECT listing_id, busy_date, code FROM calendar " .
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

function check($check_in_time, $check_out_time) {
  $conn = open_db_conn();
  $check_in = date('Y-m-d', $check_in_time);
  $check_out = date('Y-m-d', $check_out_time);
  $statement = "SELECT COUNT(1) as cnt FROM calendar " .
               "WHERE busy_date >= '{$check_in}' AND busy_date < '{$check_out}';";
  $result = mysql_query($statement, $conn);
  if (!$result) {
    throw new Exception("DB query failed");
  }
  $row = mysql_fetch_assoc($result);
  $count = intval($row['cnt']);

  return array(
    "available" => $count == 0 ? TRUE : FALSE,
    "check_in" => $check_in, 
    "check_out" => $check_out,
    "action" => "check"
  );
}

function free($listing_id, $code, $check_in_time, $check_out_time) {
  $conn = open_db_conn();
  $check_in = date('Y-m-d', $check_in_time);
  $check_out = date('Y-m-d', $check_out_time);
  $statement = "DELETE FROM calendar " .
               "WHERE listing_id='{$listing_id}' AND code='{$code}' AND " . 
                     "busy_date >= '{$check_in}' AND busy_date < '{$check_out}';";
  $result = mysql_query($statement, $conn);

  echo $statement . "\n";
  if (!$result) {
    throw new Exception("DB query failed");
  }
  return array(
    "succeed" => true,
    "listing_id" => $listing_id,
    "code" => $code,
    "check_in" => date('Y-m-d', $check_in_time),
    "check_out" => date('Y-m-d', $check_out_time),
    "action" => "free",
    "num_days_freed" => mysql_affected_rows()
  );
}

function take($listing_id, $code, $check_in_time, $check_out_time) {
  $conn = open_db_conn();
  
  $statement = 'INSERT INTO calendar (listing_id, busy_date, code) VALUES ';

  $values_to_update = array();
  $cur_time = $check_in_time;
  while ($cur_time < $check_out_time) {
    $busy_date = date('Y-m-d', $cur_time);
    $values_to_update[] = "('{$listing_id}', '{$busy_date}', '{$code}')";
    $cur_time += SECONDS_PER_DAY;
  }
  $statement = $statement . implode(',', $values_to_update) . ';';

  $result = mysql_query($statement, $conn);

  $ret =  array(
    "listing_id" => $listing_id,
    "check_in" => date('Y-m-d', $check_in_time),
    "check_out" => date('Y-m-d', $check_out_time),
    "action" => "take"
  );

  if ($result) {
    $ret["succeed"] = TRUE;
    $ret["code"] = $code;
  } else {
    $ret["succeed"] = FALSE;
  }

  return $ret;
}

function open_db_conn() {
  $conn = mysql_connect(DB_HOST, DB_USER_NAME, DB_PASSWORD);
  if (!$conn || !mysql_select_db(DB_NAME, $conn)) {
    throw new Exception("DB open failed");
  }
  return $conn;
}

function idx($array, $key) {
  return isset($array[$key]) ? $array[$key] : null;
}

function render_output($data) {
  echo json_encode($data);
}
?>
