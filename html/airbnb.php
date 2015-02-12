<?php

// Web hook actions
define("DUMP", "dump");
define("CHECK", "check");
define("TAKE", "take");
define("FREE", "free");

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
$start = idx($_GET, 'start');
$end = idx($_GET, 'end');
$action = strtolower(idx($_GET, 'action'));
$code = idx($_GET, 'code');

$start_time = strtotime($start);
$end_time = strtotime($end);
$now = time();

// headers for not caching the results
header('Cache-Control: no-cache, must-revalidate');

// headers to tell that result is JSON
header('Content-type: application/json');

if (is_null($listing_id) && ($action == TAKE || $action == FREE) ||
    is_null($code) && $action == FREE ||
    $start_time <= $now ||
    $start_time >= $end_time ||
    $end_time > $now + 365 * 24 * 60 * 60 ||
    !in_array($action, $acceptable_actions)) {
  render_output(array('error' => 'bad_input'));
  return;
}

$result = array();
$result['start'] = date('Y/m/d', $start_time);
$result['end'] = date('Y/m/d', $end_time);

try {
  switch ($action) {
    case DUMP:
      $result = dump($start_time, $end_time);
      break;
    case TAKE:
      $result = take($listing_id, $start_time, $end_time);
      break;
    case FREE:
      $result = free($listing_id, $code, $start_time, $end_time);
      break;
    case CHECK:
      $result = check($start_time, $end_time);
      break;
  }
} catch (Exception $e) {
  $error_result = array('error' => 'query_failed', 'error_message' => $e->getMessage());
  render_output($error_result);
  return;
}

render_output($result);


function dump($start_time, $end_time) {
  $conn = open_db_conn();
  $start = date('Y-m-d', $start_time);
  $end = date('Y-m-d', $end_time);
  $statement = "SELECT listing_id, busy_date, code FROM calendar " .
               "WHERE busy_date >= '{$start}' AND busy_date <= '{$end}';";
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

function check($start_time, $end_time) {
  $conn = open_db_conn();
  $start = date('Y-m-d', $start_time);
  $end = date('Y-m-d', $end_time);
  $statement = "SELECT COUNT(1) as cnt FROM calendar " .
               "WHERE busy_date >= '{$start}' AND busy_date <= '{$end}';";
  $result = mysql_query($statement, $conn);
  if (!$result) {
    throw new Exception("DB query failed");
  }
  $row = mysql_fetch_assoc($result);
  $count = intval($row['cnt']);

  return array(
    "available" => $count == 0 ? TRUE : FALSE,
    "listing_id" => $listing_id,
    "start" => $start, 
    "end" => $end,
    "action" => "check"
  );
}

function free($listing_id, $code, $start_time, $end_time) {
  $conn = open_db_conn();
  $start = date('Y-m-d', $start_time);
  $end = date('Y-m-d', $end_time);
  $statement = "DELETE FROM calendar " .
               "WHERE listing_id='{$listing_id}' AND code='{$code}' AND " . 
                     "busy_date >= '{$start}' AND busy_date <= '{$end}';";
  $result = mysql_query($statement, $conn);

  echo $statement . "\n";
  if (!$result) {
    throw new Exception("DB query failed");
  }
  return array(
    "succeed" => true,
    "listing_id" => $listing_id,
    "code" => $code,
    "start" => date('Y-m-d', $start_time),
    "end" => date('Y-m-d', $end_time),
    "action" => "free",
    "num_days_freed" => mysql_affected_rows()
  );
}

function take($listing_id, $start_time, $end_time) {
  $conn = open_db_conn();
  $code = generate_code();
  
  $statement = 'INSERT INTO calendar (listing_id, busy_date, code) VALUES ';

  $values_to_update = array();
  $cur_time = $start_time;
  while ($cur_time <= $end_time) {
    $busy_date = date('Y-m-d', $cur_time);
    $values_to_update[] = "('{$listing_id}', '{$busy_date}', '{$code}')";
    $cur_time += 24 * 60 * 60;
  }
  $statement = $statement . implode(',', $values_to_update) . ';';

  $result = mysql_query($statement, $conn);

  $ret =  array(
    "listing_id" => $listing_id,
    "start" => date('Y-m-d', $start_time),
    "end" => date('Y-m-d', $end_time),
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

function generate_code() {
  return md5(strval(time()) . "_" . strval(rand()));
}

function idx($array, $key) {
  return isset($array[$key]) ? $array[$key] : null;
}

function render_output($data) {
  echo json_encode($data);
}
?>
