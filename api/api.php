<?php
date_default_timezone_set('Asia/Seoul');
header("Content-Type:application/json");

require_once "../tmc.php";

function isRun() {
  if (file_exists("/var/run/tmc.pid")) {
    $pid = file_get_contents("/var/run/tmc.pid");
    $command = 'ps -p ' . $pid;
    exec($command, $op);
    if (isset($op[1])) {
      return true;
    }
  }
  return false;
}

Class API {
  private function searchExistFile($isRun, $json) {
    // json을 받아서 같은 내용이 있는 파일을 찾으면 파일명을 돌려줌. 아니면 false
    $md5 = md5($json);
    // req를 먼저 뒤지고
    foreach (glob("../req/*") as $filename) {
      if (md5_file($filename) == $md5) return pathinfo($filename, PATHINFO_FILENAME);
    }
    // 현재 돌고 있는 놈 찾자
    if ($isRun) {
      if (file_exists("../proc/running")) {
        $filename = file_get_contents("../proc/running");
        if (file_exists("../done/".$filename)) {
          if (md5_file("../done/".$filename) == $md5) return $filename;
        }
      }
    }
    return false;
  }

  public function convert() {
    $json = $_REQUEST['json']; 
    $proc = json_decode($json, true);
    $isRun = isRun();
    $ret['daemon'] = $isRun ? "running" : "stopped";
    if (json_last_error() === JSON_ERROR_NONE) {
      // 이미 입력된 파일인지 확인
      $filename = $this->searchExistFile($isRun, $json);
      if (empty($filename)) {
        // 파일이름 결정
        $filename = sprintf("%s%03d", date("ymdHis"), rand(1,100));

        // 파일 업로드 확인
        if (isset($proc['hires']['filelist']) && $proc['hires']['filelist'] && isset($_FILES['fileupload']) && $_FILES['fileupload']['name']) {
          $file = $_FILES['fileupload'];
          if (!move_uploaded_file($file['tmp_name'], "../proc/".$filename.".file")) {
            $ret['result'] = 'file upload failed';
            return $ret;
          }
        }

        if ((!isset($proc['proc']) && !isset($proc['template'])) || !isset($proc['hires']['site']) || !isset($proc['hires']['path_head']) || !isset($proc['hires']['path_tail']) ) {
          $ret['result'] = 'invalid json key';
          return $ret;
        }
        else {
          $config = json_decode(file_get_contents("../config/config.json"), true);
          if (!isset($config['site'][$proc['hires']['site']])) {
            $ret['result'] = 'invalid site value';
            return $ret;
          }
        }

        $saved = file_put_contents("../req/".$filename, $json);
        if ($saved >= strlen($json)) $ret['result'] = 'success';
        else $ret['result'] = 'file save failed ';
      }
      else $ret['result'] = 'duplicated';
      $ret['seq'] = $filename;
    }
    else $ret['result'] = 'invalid json format';
    return $ret;
  }

  public function status() {
    $seq = $_REQUEST['seq'];
    if (file_exists("../req/".$seq)) {
      $ret['status'] = "waiting";
    }
    else {
      $ret = array();

      if (file_exists("../proc/".$seq.".stat")) {
        $text = file_get_contents("../proc/".$seq.".stat");
        $val = explode("\t", $text);
        if ($val[1] == -1) {
          $ret['status'] = "error";
          $error_text = "";
          if (file_exists("../err/".$seq)) $error_text = file_get_contents("../err/".$seq);
          if (!empty($error_text)) {
            $error = json_decode($error_text, true);
            $ret['result'] = $error['result'];
            //$ret['detail'] = $error['file'];
          }
          else {
            $ret['result'] = "unknown error";
          }
        }
        else if ($val[0] == $val[2]) $ret['status'] = "finished";
        else $ret['status'] = "processing";
        $ret['total'] = $val[0];
        $ret['processed'] = $val[1];
        $ret['finished'] = $val[2];          
      }

      $ctrlfile = "";
      if (file_exists("../proc/".$seq.".ctrl")) $ctrlfile = "../proc/".$seq.".ctrl";
      else if (file_exists("../done/".$seq.".ctrl")) $ctrlfile = "../done/".$seq.".ctrl";
      if (!empty($ctrlfile)) {
        $ctrl = json_decode(file_get_contents($ctrlfile), true);
        $ret['status'] = $ctrl['status'];
        if (isset($ctrl['cnt'])) $ret['total'] = $ctrl['cnt'];
        if (isset($ctrl['pos'])) {
          $ret['processed'] = $ctrl['pos'];
          $ret['finished'] = $ctrl['pos'];      
        }
      }

      if (empty($ret)) {
        if (file_exists("../proc/".$seq)) {
          $ret['status'] = "starting";
        }
        if (file_exists("../done/".$seq)) {
          $ret['status'] = "finished but no info";
        }
      }
      if (empty($ret)) $ret['status'] = "not found";
    }
    return $ret;
  }

  public function seqdetail() {
    $seq = $_REQUEST['seq'];
    if (file_exists("../done/".$seq.".result")) {
      $handle = fopen("../done/".$seq.".result", "r");
      if ($handle) {
        $ret['total'] = 0;
        $ret['success'] = 0;
        $ret['error'] = 0;
        while (($line = fgets($handle)) != false) {
          $row = explode("\t", $line);
          if (trim($row[0]) != "") {
            $ret['total']++;
            if ($row[3] == 0 || $row[1] == $row[2]) $ret['success']++;
            else $ret['error']++;
          }
        }
        fclose($handle);
      } else {
        $ret['result'] = "file read error";
      } 
      $ret['result'] = "found";
    }
    else $ret['result'] = "not found";
    return $ret;
  }

  public function filelist() {
    $seq = $_REQUEST['seq'];
    if (file_exists("../done/".$seq.".result")) {
      $start = $_REQUEST['start'];
      if (!isset($start) || empty($start) || !is_numeric($start)) $start = 0;
      $offset = $_REQUEST['offset'];
      if (!isset($offset) || empty($offset) || !is_numeric($offset) || $offset > 10000 || $offset < 0) $offset = 10000;

      $handle = fopen("../done/".$seq.".result", "r");
      if ($handle) {
        $cnt = 0;
        $pos = 0;
        while (($line = fgets($handle)) != false) {
          $line = trim($line);
          $row = explode("\t", $line);
          if (trim($row[0]) != "" && $pos >= $start) {
            $ret['list'][] = $row[0];
            $cnt++;
          }
          $pos++;
          if ($cnt >= $offset) break;
        }
        fclose($handle);
        $ret['start'] = $start;
        $ret['offset'] = $cnt;
        $ret['result'] = "found";
      } else {
        $ret['result'] = "file read error";
      } 
    }
    else $ret['result'] = "not found";
    return $ret;
  }

  public function errorlist() {
    $seq = $_REQUEST['seq'];
    $start = isset($_REQUEST['start']) ? $_REQUEST['start'] : 0;
    $offset = isset($_REQUEST['offset']) ? $_REQUEST['offset'] : -1;
    if (empty($offset) || !is_numeric($offset) || $offset > 10000 || $offset < 0) $offset = 10000;

    // err 디렉토리 먼저
    $error_text = "";
    if (file_exists("../err/".$seq)) {
      $error_text = file_get_contents("../err/".$seq);
    }
    if (!empty($error_text)) {
      $error = json_decode($error_text, true);
      if (isset($error['file']) && is_array($error['file'])) {
        $size = count($error['file']);
        $pos = $start;
        $cnt = 0;
        while ($pos < $size && $cnt < $offset) {
          $ret['list'][] = $error['file'][$pos];
          $pos++;
          $cnt++;
        }
        $ret['start'] = $start;
        $ret['offset'] = $cnt;
        $ret['result'] = "found";
      }
      else $ret['result'] = "file read error";
    }
    else if (file_exists("../done/".$seq.".result")) {
      $handle = fopen("../done/".$seq.".result", "r");
      if ($handle) {
        $cnt = 0;
        $pos = 0;
        while (($line = fgets($handle)) != false) {
          $line = trim($line);
          $row = explode("\t", $line);
          if (trim($row[0]) != "" && $pos >= $start) {
            if (!empty($row[3]) || $row[1] == -1) {
              $ret['list'][] = $row[0];
              $cnt++;
            }
          }
          $pos++;
          if ($cnt >= $offset) break;
        }
        fclose($handle);
        $ret['start'] = $start;
        $ret['offset'] = $cnt;
        $ret['result'] = "found";
      } else {
        $ret['result'] = "file read error";
      } 
    }
    else $ret['result'] = "not found";
    return $ret;
  }

  public function fileresult() {
    $seq = $_REQUEST['seq'];
    $filename = $_REQUEST['val'];
    $ret['seq'] = $seq;
    $ret['filename'] = $filename;
    $ret['result'] = "not found";
    if (file_exists("../done/".$seq.".result")) {
      $handle = fopen("../done/".$seq.".result", "r");
      if ($handle) {
        while (($line = trim(fgets($handle))) != false) {
          $row = explode("\t", $line);
          if (trim($row[0]) == $filename) {
            $ret['requested']= $row[1];
            $ret['success']= $row[2];
            $ret['warning']= $row[3];
            $ret['result'] = "found";
            break;
          }
        }
        fclose($handle);
      } else {
        $ret['result'] = "file read error";
      } 
    }
    return $ret;
  }
}

$act = $_REQUEST['act'];

$api = new API();

if (method_exists($api, $act)) $response = $api->$act();
else {
    $ret['response_code'] = 400;
  $response['result'] = "Invalid act value";
}
echo json_encode($response);
?>