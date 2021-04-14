<?php

Class TMC {
  // 공통 프러퍼티와 메쏘드를 상속하기 위한 상위 클래스
	protected $config;
  // 디렉토리 상수
  protected $ROOT_DIR;
  protected $REQ_DIR; // 요청 파일 디렉토리
  protected $PROC_DIR; // 진행중 파일 디렉토리
  protected $DONE_DIR; // 완료 파일 디렉토리
  protected $LOG_DIR; // 로그 파일 디렉토리
  protected $ERR_DIR; // 에러 파일 디렉토리

  function __construct() {
    global $argv;
    $this->ROOT_DIR = dirname($argv[0]);
    if (empty($this->ROOT_DIR)) {
      if (getcwd() != '/') $this->ROOT_DIR = getcwd() . '/' . $this->ROOT_DIR;
      else $this->ROOT_DIR = getcwd();
    }
    $this->REQ_DIR = $this->ROOT_DIR."/req/"; 
    $this->PROC_DIR = $this->ROOT_DIR."/proc/"; 
    $this->DONE_DIR = $this->ROOT_DIR."/done/"; 
    $this->LOG_DIR = $this->ROOT_DIR."/log/"; 
    $this->ERR_DIR = $this->ROOT_DIR."/err/"; 
    $this->CONFIG_DIR = $this->ROOT_DIR."/config/"; 
    $this->TEMP_DIR = $this->ROOT_DIR."/temp/"; 

    $this->config = json_decode(file_get_contents($this->CONFIG_DIR."config.json"), true);
  }

  function __destruct() {
  }

  protected function writeLog($logfile, $message) {
    return file_put_contents($this->LOG_DIR.$logfile, $message, FILE_APPEND | LOCK_EX);
  }
}

Class curl {
  function __construct() {
  }

  public function call($url, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
    if (!empty($data)) {
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($ch);

    $result = json_decode($response, true);
    curl_close($ch);
    
    $retdata['url'] = $url;
    if (isset($result['code']) && $result['code'] == 1) {
      $retdata['result'] = true;
      $retdata['error_message'] = '';
    } else {
      $retdata['error_message'] = ($result->retmsg == null ? "Server connect failed." : $result->retmsg);
      $retdata['result'] = false;
      $retdata['response'] = $response;
    }
    return $retdata;
  }
}

Class BackgroundProcess {
  // TMC_daemon에서 TMC_worker 프로세스를 제어하기 위한 클래스
  private $pid;
  private $random;
  private $command;
  private $debugger = true;
  private $msg = "";
  private $isOutPut = false;
  private $logDir = "";
  private $param = "";
  /*
  * @Param $cmd: Pass the linux command want to run in background 
  */
  public function __construct($cmd = null, $param = null, $isOutPut = null) {
    if (!empty($cmd)) {
        $this->command = $cmd;
        $this->isOutPut = $isOutPut;
        $this->param = $param;
        $this->do_process();
    } else {
        $this->msg['error'] = "Please Provide the Command Here";
    }
  }

  public function getParam() {
    return $this->param;
  }

  public function setCmd($cmd) {
    $this->command = $cmd;
    return true;
  }

  public function setProcessId($pid) {
      $this->pid = $pid;
      return true;
  }

  public function getProcessId() {
      return $this->pid;
  }

  public function status() {
      $command = 'ps -p ' . $this->pid;
      exec($command, $op);
      if (!isset($op[1])) return false;
      else return true;
  }

  public function showAllProcess() {
      $command = 'ps h --ppid ' . $this->pid . ' -o pid';
      exec($command, $op);
      return $op;
  }

  public function start($isOutPut = null) {
      $this->isOutPut = $isOutPut;
      if ($this->command != '')
          $this->do_process();
      else return true;
  }

  public function stop() {
      $command = 'kill ' . $this->pid;
      exec($command);
      if ($this->status() == false) return true;
      else return false;
  }

  public function term() {
    $command = 'kill -TERM'. $this->pid;
      exec($command);
      if ($this->status() == false) return true;
      else return false;
  }

  public function get_log_paths() {
      return "Log path: \nstdout: /tmp/out_" . $this->random . "\nstderr: /tmp/error_out_" . $this->random . "\n";
  }

  public function create_command_string() {
      $outPath = ' > /dev/null 2>&1';
      if ($this->isOutPut) {
          $this->random = rand(5, 15);
      }
      return 'nohup ' . $this->command.$this->param.' >/dev/null 2>&1 & echo $!';
  }

  public function do_process() {
      $command = $this->create_command_string();
      exec($command, $process);
      $this->pid = (int) $process[0];
  }
}

Class dailyTar {
  // 디렉토리를 주면 며칠 이전 파일이 있을 경우 그 날짜로 압축 보관해버리는 클래스
  private $zdays = 2; // 최소 날짜 
  private $dateformat = "ymd";
  private $dirs; // 소스-목적 으로 된 array
  private $dst = "";
  private $src = "";
  private $rules;
  private $lastworktime = 0;
  private $command = "tar cfz %dst%.tar.gz -C %srcdir% %src% --remove-files";

  function __construct($option, $init = true) {
    // example option = {dst:/opt/tmc/done/%date%, src:/opt/tmc/done/%date%*, zdays:2}
    foreach ($option as $k => $v) {
      if (isset($this->$k)) {
        $this->$k = $v;
      }
    }

    if ($init) {
      $this->work();
    }
  }

  private function doTar($files) {
    $cmd = $this->command;
    $cmd = str_replace("%src%", implode(" ", $files['file']), $cmd);
    $cmd = str_replace("%dst%", $this->dst, $cmd);
    $cmd = str_replace("%date%", $files['date'], $cmd);
      
    $path = pathinfo($this->src);
    
    $cmd = str_replace("%srcdir%", $path['dirname'], $cmd);
    exec($cmd);
  }

  private function getOldestFile($path) {
    // path 디렉토리의 가장 오래된 날짜의 파일을 가져옴. 리턴은 날짜와 파일 목록
    $ret = array("date"=>"99999999","file"=>array());

    foreach (glob($path) as $filename) {
      if (substr($filename, -3) == ".gz") continue;
      $dt = date($this->dateformat, filemtime($filename));
      if ($ret['date'] > $dt) {
        $ret['date'] = $dt;
        $ret['file'] = array(basename($filename));
      }
      else if ($ret['date'] < $dt) {
        continue;
      }
      else {
        $ret['file'][] = basename($filename);
      }
    }
    return $ret;
  }

  public function work() {
    if (Date("md", $this->lastworktime) == Date("md")) {
      // 현재 날짜가 최근 실행 날짜와 같으면 종료
      return false;
    }
    $basedt = date($this->dateformat, time() - $this->zdays * 60*60*24);
    while (true) {
      $files = $this->getOldestFile($this->src);
      //var_dump($files);
      if ($files['date'] > $basedt) break;
      $this->doTar($files);
    }
    $this->lastworktime = time();
    return true;
  }
}