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

$listing_id = idx($_GET, 'listing_id');
$check_in = idx($_GET, 'check_in');
$check_out = idx($_GET, 'check_out');
$action = strtolower(idx($_GET, 'action'));
$code = idx($_GET, 'code');

$check_in_time = strtotime($check_in);
$check_out_time = strtotime($check_out);

// headers for not caching the results
header('Cache-Control: no-cache, must-revalidate');

// headers to tell that result is JSON
header('Content-type: application/json');

try {
  sanity_check_input($action, $listing_id, $check_in, $check_out, $check_in_time, $check_out_time, $code);
} catch (Exception $e) {
  render_output(array('error' => 'bad_input', 'error_message' => $e->getMessage()));
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
  render_output(array('error' => 'query_failed', 'error_message' => $e->getMessage()));
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

function sanity_check_input($action, $listing_id, $check_in, $check_out, $check_in_time, $check_out_time, $code) {
  if (is_null($check_in) || is_null($check_out)) {
    throw new Exception("missing check_in or check_out date");
  }

  if (is_null($listing_id) && ($action == TAKE || $action == FREE)) {
    throw new Exception("listing_id missing");
  }

  if (is_null($code) && ($action == TAKE || $action == FREE)) {
    throw new Exception("code missing");
  }

  $now = time();
  if ($check_in_time <= $now - SECONDS_PER_DAY) {
    throw new Exception("check_in is a past date");
  }

  if ($check_in_time >= $check_out_time) {
    throw new Exception("check_out date is before or equal to the check_in date");
  }

  if ($check_out_time > $now + 365 * SECONDS_PER_DAY) {
    throw new Exception("out of supported check out date range");
  }

  $permit_actions = array(DUMP, CHECK, TAKE, FREE);
  if (!in_array($action, $permit_actions)) {
    throw new Exception("Unsupported action. Available actions are " . implode(', ', $permit_actions));
  }
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
