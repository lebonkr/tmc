<?php
date_default_timezone_set('Asia/Seoul');
require_once "tmc.php";

define ('MODE_IDLE', 0);
define ('MODE_WORKING', 1);
define ('MODE_PAUSED', 2);
define ('MODE_STOPPED', 3);
define ('MODE_CANCELLED', 4);
define ('MODE_ERROR', 5);
define ('MODE_WAITING', 6); // worker가 디렉토리 뒤지는거 기다려야 함

Class TMC_client extends TMC {
  private $seq = "";
  private $data = null;
  private $mode = MODE_IDLE;
  private $que = array(); // 클라이언트의 큐는 워커에게 보낸 work의 저장소

  private $rec_count = 0; // 전체 갯수
  private $rec_current = 0; // 현재 (다음에 가져갈) 진행 위치
  private $rec_finished = 0; // 완료된 갯수. 워커가 완료했다는 연락을 받아야 업데이트
  private $filehandle = null;

	public function __construct($seq) {
    parent::__construct();
    $this->seq = $seq;

    $this->data = json_decode(file_get_contents($this->PROC_DIR.$seq), TRUE);
    if (!isset($this->data['hires'])) {
      $this->writeLog("job has no hires : ".$seq, __FILE__, __LINE__);
      return false;
    }

    // ctrl 있으면 초기 상태 세팅
    // paused 면 멈춘뒤에 종료된 경우, stopped면 데몬이 정지된 경우, cancelled 면 취소된 경우임
    if (file_exists($this->PROC_DIR.$this->seq.".ctrl")) {
      $command = json_decode(file_get_contents($this->PROC_DIR.$this->seq.".ctrl"), true);
      if (isset($command['status'])) {
        if ($command['status'] == "paused" || $command['status'] == "stopped" ) {
          $this->rec_count = $command['cnt'];
          $this->rec_current = $command['pos'];
          $this->filehandle = fopen($this->PROC_DIR.$this->seq.".file","r");
          for ($i = 0; $i < $this->rec_current; $i++) {
            $this->getOneLine();
            $this->rec_finished++;
          }
          if ($command['status'] == "paused") $this->mode = MODE_PAUSED;
          else $this->mode = MODE_WORKING;
        }
        else if ($command['status'] == "cancelled") {
          $this->writeLog(sprintf("ERROR : Client constructor found cancel command [%s]- %s",  $this->seq, json_encode($command)));
          rename($this->PROC.$seq, $this->DONE_DIR.$seq);
          $this->mode = MODE_CANCELLED;
        }
        else if ($command['status'] == "pause") {
          $this->mode = MODE_PAUSED;
        }
      }
      else {
        $this->writeLog(sprintf("ERROR : Work found ctrl file but not valid - %s", json_encode($command)));
        $this->mode = MODE_ERROR;
      }
      if ($this->mode != MODE_PAUSED) unlink($this->PROC_DIR.$this->seq.".ctrl");
    }

    if (isset($this->data['hires']['filelist']) && $this->data['hires']['filelist']) {
      // 파일 리스트를 받은 경우 원본이 모두 존재하는지 확인하지 않음. 돌면서 에러 나기 때문
      // proc/에 .file은 이미 있어야 함
      if (!file_exists($this->PROC_DIR.$this->seq.".file")) {
        $this->writeLog(sprintf("ERROR : hires.filelist is true, but %s.file does not exist.", $this->seq));
        $this->mode = MODE_ERROR;
        return false;
      }
      // rec_count가 누락되었었음
      $this->rec_count = $this->getLineCountFromTextfile($this->PROC_DIR.$this->seq.".file");

    }
    else if ($this->rec_current == 0 && !file_exists($this->PROC_DIR.$this->seq.".file")) {
        // 소스 파일 찾기
        $this->mode = MODE_WAITING;
    }

    if ($this->openListFile()) $this->mode = MODE_WORKING;

    if ($this->mode == MODE_IDLE) $this->mode = MODE_WORKING;
  }

  private function getLineCountFromTextfile($file) {
    $linecount = 0;
    $handle = fopen($file, "r");
    while(!feof($handle)){
      $line = trim(fgets($handle));
      if (!empty($line)) $linecount++;
    }
    fclose($handle);
    return $linecount;
  }

  private function openListFile() {
    // .file을 오픈하고 핸들을 읽어둠. 없거나 오류이면 false 리턴. 있으면 true리턴
    // rec_count > 0이라면 .file이 당연히 있고, 0라면 .file이 없을것임. 따라서 파일이 없으면 0이상 진행하지 않을 것이니 에러처리 하지 않아도 될걸?
    // mode == MODE_PAUSED이거나 MODE_STOPPED이면 이미 .file을 읽었을 것이고 그러면 return false이어야 함
    if ($this->filehandle == null && file_exists($this->PROC_DIR.$this->seq.".file")) {
      $this->filehandle = fopen($this->PROC_DIR.$this->seq.".file","r");
      if (file_exists($this->PROC_DIR.$this->seq.".stat")) 
        $this->rec_count = file_get_contents($this->PROC_DIR.$this->seq.".stat");
      $this->rec_current = 0;
      return true;
    }
    return false;
  }

  protected function writeLog($log, $fn = __FILE__, $line = __LINE__)
  {
    $text = sprintf("%s [%d][%s:%d] - %s".PHP_EOL, Date("ymdHis"), getmypid(), basename($fn), $line, $log);
    parent::writeLog("TMC_client.".Date("ymd").".log", $text);
  }

  public function getSeq() {
    return $this->seq;
  }

  private function getOneLine() {
    if (!feof($this->filehandle)) {
      $line = fgets($this->filehandle);
      return trim($line);
    }
    return null;
  }

  private function isBigFile($seq, $source) {
    // 가로 x 세로 해상도가 100000000 이상일 경우 big file로 판단함
    // 메모리를 8기가까지 점유할수 있기 때문에 워커 갯수를 제한하여 배정함
    $req = json_decode(file_get_contents($this->PROC_DIR.$seq), true);
    if (empty($req)) return false;

    $src_file = $this->config['site'][$req['hires']['site']]['root'].$req['hires']['path_head'].$req['hires']['path_tail'].$source;
    if (!file_exists($src_file)) {
      $this->writeLog("ERROR : source file not found :".$src_file, __FILE__, __LINE__);
      return false;
    }

    $im = new Imagick();
    try {
      $im->pingImage($src_file);
      $w = $im->getImageWidth();
      $h = $im->getImageHeight();
      $im->clear();
    }
    catch (Exception $e){
      $this->writeLog("ERROR : isBigFile::pingImage failed:".$src_file, __FILE__, __LINE__);
      return false;
    }

    return ($w * $h > 100000000);
  }

  public function getNewWork() {
    // 단위 work를 1개씩 넘겨주는 함수. 더 없으면 null 리턴
    $ret = null;
    if ($this->mode == MODE_WAITING) {
      // 스캔해야 하면 이것부터 워커로 돌림
      $ret = array("proc" => $this->seq, "source" => "", "func" => "scan");
      $this->mode = MODE_IDLE;
      return $ret;
    }
    else if ($this->mode == MODE_WORKING && $this->rec_current < $this->rec_count) {
      $line = $this->getOneLine();
      if (!empty($line)) {
        $ret = array("proc" => $this->seq, "source" => $line, "func" => ($this->isBigFile($this->seq, $line)? "bigconvert": "convert"));
        $this->rec_current++;
        return $ret;
      }
    }
    return null;
  }

  public function scanFinished() {
    if ($this->openListFile()) $this->mode = MODE_WORKING;

    $line = sprintf("%d\t%d\t%d", $this->rec_count, $this->rec_current, $this->rec_finished);
    file_put_contents($this->PROC_DIR.$this->seq.".stat", $line);
  }

  public function jobFinished($work) {
    $this->rec_finished++;    

    $line = sprintf("%d\t%d\t%d", $this->rec_count, $this->rec_current, $this->rec_finished);
    file_put_contents($this->PROC_DIR.$this->seq.".stat", $line);
  }

  public function isFinished() {
    if ($this->mode == MODE_WORKING || $this->mode == MODE_PAUSED) return ($this->rec_finished >= $this->rec_count);
    return ($this->rec_finished >= $this->rec_current);
  }

  public function getMode() { return $this->mode; }

  public function pause() {
    // 상태를 pause로 변경. 리턴값은 이 클라이언트를 종료해야 하면 true, 아니면 false
    if (file_exists($this->PROC_DIR.$this->seq.".ctrl"))
      $command = json_decode(file_get_contents($this->PROC_DIR.$this->seq.".ctrl"), TRUE);
    $command['status'] = "paused";
    file_put_contents($this->PROC_DIR.$this->seq.".ctrl", json_encode($command), LOCK_EX);
    $this->mode = MODE_PAUSED;
    return false;
  }

  public function resume() {
    // 상태를 working으로 변경. 리턴값은 false
    if (file_exists($this->PROC_DIR.$this->seq.".ctrl"))
      $command = json_decode(file_get_contents($this->PROC_DIR.$this->seq.".ctrl"), TRUE);
    if ($command['status'] == "resume") {
      unlink($this->PROC_DIR.$this->seq.".ctrl");
      $this->mode = MODE_WORKING;
    }
    return false;
  }

  public function stop() {
    // 상태를 stop로 변경. 리턴값은 이 클라이언트를 종료해야 하면 true, 아니면 false
    if ($this->mode != MODE_PAUSED) {
      // pause 상태이면 ctrl을 업데이트하지 말고 그냥 종료하면 됨
      if (file_exists($this->PROC_DIR.$this->seq.".ctrl"))
        $command = json_decode(file_get_contents($this->PROC_DIR.$this->seq.".ctrl"), TRUE);
      $command['status'] = "stopped";
      file_put_contents($this->PROC_DIR.$this->seq.".ctrl", json_encode($command), LOCK_EX);
    }
    $this->mode = MODE_STOPPED;
    return ($this->rec_finished > $this->rec_current);
  }

  public function cancel() {
    // 상태를 cancelled로 변경. 리턴값은 이 클라이언트를 종료해야 하면 true, 아니면 false
    if (file_exists($this->PROC_DIR.$this->seq.".ctrl"))
      $command = json_decode(file_get_contents($this->PROC_DIR.$this->seq.".ctrl"), TRUE);
    $command['status'] = "cancelled";
    file_put_contents($this->PROC_DIR.$this->seq.".ctrl", json_encode($command), LOCK_EX);
    $this->mode = MODE_CANCELLED;
    return ($this->rec_finished > $this->rec_current);
  }

  private function readResultFileCount() {
    $ret = array('processed'=> 0, 'created' => 0, 'warn' => 0);
    if (file_exists($this->DONE_DIR.$this->seq.".result")) {
      $handle = fopen($this->DONE_DIR.$this->seq.".result", "r");
      if ($handle) {
        while (($line = fgets($handle)) !== false) {
          $line = trim($line);
          $row = explode("\t", $line);
          if (count($row) > 3) {
            $ret['processed'] += 1;
            $ret['created'] += intval($row[2]);
            $ret['warn'] += intval($row[3]);
          }
        }
        fclose($handle);
      }
    }
    return $ret;
  }

  private function callback() {
    if (isset($this->data['hires']['callback']) && !empty($this->data['hires']['callback'])) {
      $postdata = $this->readResultFileCount();
      $postdata['seq'] = $this->seq;
      $postdata['result'] = ($this->rec_current == $postdata['processed'] && $postdata['warn'] == 0 && $postdata['processed'] > 0)? 1 : 0;
      $postdata['count'] = $this->rec_count;
      $curl = new curl();
      $ret = $curl->call($this->data['hires']['callback'], $postdata);
      $this->writeLog("curl result=".json_encode($ret));
    }
  }

  public function close() {
    if ($this->mode == MODE_PAUSED || $this->mode == MODE_STOPPED) {
      // 멈췄거나 종료가 오면 일단 현재 상태 보관한 후 저장
      $ctrl_file = $this->PROC_DIR.$this->seq.".ctrl";
      $command = json_decode(file_get_contents($ctrl_file), true);
      $command['pos'] = $this->rec_current;
      $command['cnt'] = $this->rec_count;
      file_put_contents($ctrl_file, json_encode($command), LOCK_EX);
      rename($this->PROC_DIR.$this->seq, $this->REQ_DIR.$this->seq);
    }
    else if ($this->mode == MODE_CANCELLED) {
      // 취소 요청이면 모두 오류로 처리하고 종료, ctrl 파일은 DONE 디렉토리에 남김
      $line = "";
      while ($this->rec_current < $this->rec_count && $line != null) {
        $line = $this->getOneLine();
        if (!empty($line)) {
          $text = sprintf("%s\t0\t0\t0\n", $line);
          file_put_contents($this->DONE_DIR.$this->seq.".result", $text, FILE_APPEND | LOCK_EX);
        }
        $this->rec_current++;
      }
      rename($this->PROC_DIR.$this->seq, $this->DONE_DIR.$this->seq);
      rename($this->PROC_DIR.$this->seq.".ctrl", $this->DONE_DIR.$this->seq.".ctrl");
      $this->callback();
    }
    else if ($this->mode == MODE_ERROR) {
      rename($this->PROC_DIR.$this->seq, $this->ERR_DIR.$this->seq);
      $this->callback();
    }
    else {
      // MODE_WORKING이거나 MODE_IDLE이면 DONE으로 이동
      rename($this->PROC_DIR.$this->seq, $this->DONE_DIR.$this->seq);
      rename($this->PROC_DIR.$this->seq.".file", $this->DONE_DIR.$this->seq.".file"); // 리스트 파일 DONE으로 이동
      $this->callback();
    }

    if ($this->filehandle != null) fclose($this->filehandle);
  }
}

Class TMC_daemon extends TMC {
  private $client_cnt;
  private $clients = array(); // 클라이언트 프로세스 저장하는 큐
  private $workers = array(); // 워커 프로세스 저장하는 배열
  private $watchdogs = array(); // Watchdog 프로세스 저장하는 배열
  private $finished = 0;
  private $exit = false;
  private $pause = false;
  private $status = false;

	private $gmc;
  private $que_size;
  private $que = array(); // 핸들 저장하는 큐
  private $dailyTars = array(); // 데일리 파일 정리 객체의 배열

	public function __construct() {
    parent::__construct();

    $this->writeLog("TMC Daemon started..", __FILE__, __LINE__);

    // 이미 실행중인지 확인
    if (file_exists("/var/run/tmc.pid")) {
      $pid = file_get_contents("/var/run/tmc.pid");
      $command = 'ps -p ' . $pid;
      exec($command, $op);
      if (isset($op[1])) {
        echo "Already tmc daemon is running!";
        exit;
      }
    }
    // config 확인
    if (!isset($this->config) || empty($this->config)) {
      $this->writeLog("Error : No config found", __FILE__, __LINE__);
    }

    // self pid 생성
    file_put_contents("/var/run/tmc.pid", getmypid());

    $this->client_cnt = $this->config['client_cnt'];
    $this->que_size = $this->config['worker_cnt'];
    for ($i = 0; $i < $this->que_size; $i++) {
      $this->que[$i] = null;
    }

    # create the gearman client
    $this->gmc = new GearmanClient();

    # add the default server (localhost)
    try {
      $this->gmc->addServer();
    }
    catch (Exception $e){
      $err = "ERROR: Gearmand not connected";
      $this->writeLog($err, __FILE__, __LINE__);
      echo $err.PHP_EOL;
      exit;
    }

    // worker process 생성
    $bigworker = isset($this->config['big_worker']) ? $this->config['big_worker'] : 1;
    for ($i = 0; $i < $this->config['worker_cnt']; $i++) {
      $worker = new BackgroundProcess('/usr/bin/php -f '.$this->ROOT_DIR.'/tmc_worker.php '.($bigworker > 0 ? "1" : "0"));
      $this->writeLog("worker created [".$worker->getProcessId()."]", __FILE__, __LINE__);
      $this->workers[] = $worker;
      if ($bigworker > 0) $bigworker--;
    }

    // watchdog que 추가
    if (isset($this->config['watchdog']) && !empty($this->config['watchdog'])) {
      foreach ($this->config['watchdog'] as $site => $dog) {
        $dog['site'] = $site;
        $this->watchdogs[] = $dog;
      }
    }

    // signal handler 연결
    pcntl_signal(SIGTERM, array(&$this, "sig_handler"));
    pcntl_signal(SIGUSR1, array(&$this, "sig_handler"));
    pcntl_signal(SIGTSTP, array(&$this, "sig_handler"));

    // 파일 백업
    $this->dailyTars[] = new dailyTar(json_decode('{"dst":"/opt/tmc/done/%date%", "src":"/opt/tmc/done/*", "zdays":2}', true), true);
    $this->dailyTars[] = new dailyTar(json_decode('{"dst":"/opt/tmc/proc/%date%", "src":"/opt/tmc/proc/*", "zdays":2}', true), true);
    $this->dailyTars[] = new dailyTar(json_decode('{"dst":"/opt/tmc/temp/%date%", "src":"/opt/tmc/temp/*", "zdays":2}', true), true);
    $this->dailyTars[] = new dailyTar(json_decode('{"dst":"/opt/tmc/log/%date%", "src":"/opt/tmc/log/*", "zdays":2}', true), true);
	}

  function __destruct() {
    // worker들에게 term 날림
    $workercnt = 0;
    foreach ($this->workers as $worker) {
      $worker->stop();
      $workercnt++;
    }
    // worker가 다 죽었는지 대기
    while ($workercnt > 0) {
      $workercnt = 0;
      foreach ($this->workers as $worker) {
        if ($worker->status()) $workercnt++;
      }
      $this->writeLog("worker alive count=".$workercnt, __FILE__, __LINE__);
      sleep(1);
    }   
    unset($this->dailyTars);

    unlink("/var/run/tmc.pid");

    parent::__destruct();
  }

  public function writeLog($log, $fn = __FILE__, $line = __LINE__)
  {
    $text = sprintf("%s [%d][%s:%d] - %s".PHP_EOL, Date("ymdHis"), getmypid(), basename($fn), $line, $log);
    parent::writeLog("TMC_daemon.".Date("ymd").".log", $text);
  }

  private function load_work_request() {
    // scan req dir, read first file & return
    // 리턴은 구조체를 돌려줌
    $files = scandir($this->REQ_DIR);
    $ret = null;
    if (!empty($files)) {
      foreach ($files as $file) {
        if ($file != "." && $file != "..") {
          $filename = $this->REQ_DIR.$file;
          // aborted 상태인지부터 체크
          $command = array();
          if (file_exists($this->PROC_DIR.$file.".ctrl")) {
            $command = json_decode(file_get_contents($this->PROC_DIR.$file.".ctrl"), TRUE);
          }

          if (isset($command['status'])) {
            if ($command['status'] == "stopped") {
              $command['status'] = "resume";
              file_put_contents($this->PROC_DIR.$file.".ctrl", json_encode($command), LOCK_EX);
            }
            if ($command['status'] == "cancel") {
              rename($filename, $this->DONE_DIR.$file);
              continue;
            }
          }
          $ret = json_decode(file_get_contents($filename), TRUE);
          if (json_last_error() !== JSON_ERROR_NONE || empty($ret)) 
            rename($filename, $this->ERR_DIR.$file);
          else {
            if (file_exists($this->DONE_DIR.$file)) {
              $this->writeLog("ERROR: File already exists - ".$this->DONE_DIR.$file, __FILE__, __LINE__);
              return null;
            }
            rename($filename, $this->PROC_DIR.$file);
            return $file;
          }
        }
      }
    }
    return null;
  }

  private function getWorkerQue() {
    // 빈 큐를 찾아 돌려줌, 리턴은 0보다 같거나 크면 큐 번호
    for ($i = 0; $i < $this->que_size; $i++) {
      if ($this->que[$i] == null) return $i;
    }
    return -1;
  }

  private function get_countQue() {
    // 사용되고 있는 큐의 갯수를 리턴
    $ret = 0;
    for ($i = 0; $i < $this->que_size; $i++) {
      if ($this->que[$i] != null) $ret++;
    }
    return $ret;
  }

  private function check_Que() {
    // 큐를 검사하고 완료된것 있으면 null 처리함. 변동이 있으면 true, 아니면 false 리턴
    $ret = false;
    for ($i = 0; $i < $this->que_size; $i++) {
      if ($this->que[$i] != null) {
        $stat = $this->que[$i]['handle']->status();
        if (!$stat) {
          // 분배된 잡이 끝났음
          $this->writeLog(sprintf("[%s]Job Finished : %s", $this->clients[$i]->getProcessId(), $this->clients[$i]->getParam()));
          $ret = true;
          unset($this->clients[$i]);
          $this->clients[$i] = null;
          $this->finished++;
        }
      }
    }
  
    return $ret;
  }

  public function sig_handler($sigNo) {
    if ($sigNo == SIGTERM || $sigNo == SIGINT) {
      $this->writeLog("SIGINT got($sigNo)", __FILE__, __LINE__);
      $this->exit = true;
      foreach ($this->clients as $k => $client) {
        $this->clients[$k]->stop();
      }
    }
    else if ($sigNo == SIGUSR1) {
      $this->status = true;
    }
    else if ($sigNo == SIGTSTP) $this->pause = !$this->pause;
  }

  private function getNewRequest() {
    $files = scandir($this->REQ_DIR);
    $ret = null;
    if (!empty($files)) {
      foreach ($files as $file) {
        if ($file != "." && $file != "..") {
          $filename = $this->REQ_DIR.$file;
          $ret = json_decode(file_get_contents($filename), TRUE);
          if (empty($ret)) rename($filename, $this->ERR_DIR.$file);
          else {
            if (file_exists($this->DONE_DIR.$file)) {
              rename($filename, $this->ERR_DIR.$file);
              $this->writeLog("ERROR: File already exists, moved to Error directory ".$file, __FILE__, __LINE__);
              return null;
            }
            rename($filename, $this->PROC_DIR.$file);
            return $file;
          }
        }
      }
    }
    return null;
  }

  private function countClientJobInQue($seq) {
    $ret = 0;
    for ($i = 0; $i < $this->que_size; $i++) {
      if ($this->que[$i] != null && $this->que[$i]['seq'] == $seq) $ret++;
    }
    return $ret;
  }

  private function checkCtrl() {
    foreach ($this->clients as $k => $client) {
      if (!empty($client)) {
        $seq = $client->getSeq();
        if (file_exists($this->PROC_DIR.$seq.".ctrl")) {
          $command = json_decode(file_get_contents($this->PROC_DIR.$seq.".ctrl"), TRUE);
          $close = false;
          if (isset($command['status'])) {
            if ($command['status'] == "pause") $close = $client->pause();
            else if ($command['status'] == "stop") $close = $client->stop();
            else if ($command['status'] == "cancel") $close = $client->cancel();
            else if ($command['status'] == "resume") $close = $client->resume();
          }
          if ($close) {
            $client->close();
            unset($this->clients[$k]);
          }
        }
      }
    }
  }

  public function run() {
    // SIGTERM 체크
    pcntl_signal_dispatch(); // signal dispatch
    // SIGTERM이 들어왔으면 프로세스 종료
    // 종료 예정이 아니면
    if (!$this->exit) {
      // 클라이언트 빈자리 있으면
      if (count($this->clients) < $this->client_cnt) {
        // 새로운 요청 확인해보고
        $req = $this->getNewRequest();
        if (!empty($req)) {
          // 있으면 새 클라이언트 만들어 넣자
          $client = new TMC_Client($req);
          $this->writeLog("new client added:".$req, __FILE__, __LINE__);
          array_push($this->clients, $client);
        }
      }

      $que_num = $this->getWorkerQue();
      // 노는 워커 있으면
      if ($que_num > -1) {
        // watchdog 부터 꺼내보자. watchdog은 1개만 실행되니까
        $cntwd = $this->countWathDogInWorker();
        if ($cntwd == 0) {
          $wd = array_shift($this->watchdogs);
          if ($wd != null) {
            // minsec 계산
            $lasttime = trim(file_get_contents($this->CONFIG_DIR.$wd['site'].".watchdog"));
            $now = time() - 300; // 5분 전이 기준시간임
            $minsec = isset($this->config['watchdog'][$wd['site']]['min_sec']) ? $this->config['watchdog'][$wd['site']]['min_sec'] : 1200;

            if (empty($lasttime) || $now - $lasttime >= $minsec) {
              //$this->writeLog("lasttime=$lasttime,now=$now(".date("Y-m-d H:i:s",$now)."),now-lasttime=".($now - $lasttime)."minsec=$minsec", __FILE__, __LINE__);
              $workload = array('site' => $wd['site'], 'func' => "watchdog");
              $workload["proc"] = sprintf("wd%s%03d", date("ymdHis"), rand(1,100));

              //$this->writeLog("watchdog to worker:".json_encode($workload), __FILE__, __LINE__);
              // 워커에게 배당하고
              $job_handle = $this->gmc->doBackground($workload['func'], json_encode($workload));

              if ($this->gmc->returnCode() != GEARMAN_SUCCESS)
              {
                $this->writeLog("ERROR : Gearman bad return code", __FILE__, __LINE__);
              }
              else {
                // 생성된 작업을 큐에 추가
                //$this->writeLog("job created". $job_handle. ", workload: ".$work_load);
                $this->que[$que_num] = array("handle"=>$job_handle, "seq"=>$workload['proc'], "site"=>$workload['site'], "source" =>"", "func" => $workload['func']);
                $que_num = -1;
              }
            }
            array_push($this->watchdogs, $wd);
            usleep($this->config['sleep_delay']);
          }
        }

        // 클라이언트 하나 꺼내보고
        if ($que_num > -1) {
          $client = array_shift($this->clients);
          if ($client != null) {

            // 시킬일 있나?
            $workload = $client->getNewWork();
            if (!empty($workload)) {
              $this->writeLog("client shifted:".$client->getSeq(), __FILE__, __LINE__);
              //$this->writeLog("work to worker:".json_encode($workload), __FILE__, __LINE__);
              // 워커에게 배당하고
              $job_handle = $this->gmc->doBackground($workload['func'], json_encode($workload));

              if ($this->gmc->returnCode() != GEARMAN_SUCCESS)
              {
                $this->writeLog("ERROR : Gearman bad return code", __FILE__, __LINE__);
              }
              else {
                // 생성된 작업을 큐에 추가
                //$this->writeLog("job created". $job_handle. ", workload: ".$work_load);
                $this->que[$que_num] = array("handle"=>$job_handle, "seq"=>$workload['proc'], "source" => $workload['source'], "func" => $workload['func']);
              }
              array_push($this->clients, $client);
              usleep($this->config['sleep_delay']);
            }
            else {
              if ($client->isFinished() && $this->countClientJobInQue($client->getSeq()) < 1) {
                $this->writeLog("client finished:".$client->getSeq(), __FILE__, __LINE__);
                // 클라이언트 일이 모두 끝났으면
                $client->close();
                unset($client);
              }
              else {
                // 아니면 다시 클라이언트 큐에 넣어둠
                //$this->writeLog("client repush:".$client->getSeq(), __FILE__, __LINE__);
                array_push($this->clients, $client);
                usleep($this->config['sleep_delay']);
              }
            }
          }
        }
      }
    }
    else {
      // exit = true가 되면 워커에게 일을 시킨 클라이언트는 워커가 일을 끝내고 리턴한뒤에 처리하면서 종료해도 되는지 체크하지만, pause된 클라이언트들은 체크하지 못하게 되기 때문에 여기서 클라이언트들을 체크해보고 종료해도 되는 놈들은 종료시킴
      foreach ($this->clients as $k => $v) {
        // 클라이언트가 더 이상 할 일이 없으면 
        if ($this->clients[$k]->isFinished()) {
          //$this->writeLog("client finished:".$this->clients[$k]->getSeq(), __FILE__, __LINE__);
          $this->clients[$k]->close();
          unset($this->clients[$k]);
        }
      }
    }
    // 워커 일 끝났는지 확인하자
    $quecount = $this->checkWorkerResponse();

    // ctrl 확인하고
    $this->checkCtrl();

    // exit이고 클라이언트가 비었으면 끝
    if ($this->exit && empty($this->clients)) return false;
    else if ($this->status) {
      // status는 여기서 출력
      $this->showStatus($quecount);
    }

    foreach ($this->dailyTars as $dailyTar) {
      $dailyTar->work();
    }

    return true;
  }

  private function showStatus($workcount) {
    $text = sprintf("TMC status:running [%s]\n(working/running) workers : (%d/%d)\nTotal processed : %d\n", Date("Y-m-d H:i:s"), $workcount, $this->que_size, $this->finished);
    file_put_contents($this->ROOT_DIR."/status", $text);
    $this->status = false;    
  }

  private function countWathDogInWorker() {
    $ret = 0;
    for ($i = 0; $i < $this->que_size; $i++) {
      if ($this->que[$i] != null && ($this->que[$i]['func'] == "watchdog" || substr($this->que[$i]['seq'], 0, 2) == "wd"))  $ret++;
    }
    return $ret;
  }

  private function checkWorkerResponse() {
    // return 값은 현재 배정되어 일하고 있는 워커의 수
    $ret = 0;
    for ($i = 0; $i < $this->que_size; $i++) {
      if ($this->que[$i] != null) {
        $stat = $this->gmc->jobStatus($this->que[$i]['handle']);
        if (!$stat[0]) {
          // 분배된 잡이 끝났음
          // watchdog이면 클라이언트 생성 전이므로 먼저 확인하고
          if ($this->que[$i]['func'] == "watchdog") {
            // .file이 생성되어 있으면 watchdog을 가지고 proc/seq 파일을 만들어서 클라이언트를 생성함
            if (file_exists($this->PROC_DIR.$this->que[$i]['seq'].".file")) {
              $site = $this->que[$i]['site'];
              $req = $this->que[$i]['seq'];
              $rename = isset($this->config["watchdog"][$site]["rename"]) ? sprintf(',"rename":%s', json_encode($this->config["watchdog"][$site]["rename"])) : "";
              $capture = isset($this->config["watchdog"][$site]["capture"]) ? sprintf(',"capture":%d', $this->config["watchdog"][$site]["capture"]) : "";
              $overwrite = isset($this->config["watchdog"][$site]["overwrite"]) ? sprintf(',"overwrite":%s', $this->config["watchdog"][$site]["overwrite"] ? "true":"false") : "";
              $proc = sprintf('{"hires":{"site":"%s","path_head":"%s","path_tail":"","include":%s %s,"exclude":%s %s %s,"filelist":true},"template":"%s"}', $site, $this->config["watchdog"][$site]["dir"], json_encode($this->config["watchdog"][$site]["include"]), $rename, json_encode($this->config["watchdog"][$site]["exclude"]), $capture, $overwrite, $this->config["watchdog"][$site]["template"]);
              file_put_contents($this->PROC_DIR.$req, $proc);
              $client = new TMC_Client($req);
              $this->writeLog("new watchdog client added:".$req, __FILE__, __LINE__);
              // watchdog은 클라이언트 갯수 체크없이 예외적으로 추가함 
              array_push($this->clients, $client);
            }
          }
          else {
            // watchdog이 아닐때에만 클라이언트에서 확인함
            foreach ($this->clients as $k => $v) {
              if ($this->clients[$k]->getSeq() == $this->que[$i]['seq']) {
                if ($this->que[$i]['func'] == "convert" || $this->que[$i]['func'] == "bigconvert") {
                  //$this->writeLog("worker found finished:".$this->que[$i]['seq'], __FILE__, __LINE__);
                  $this->clients[$k]->jobFinished($this->que[$i]['source']);

                  // 클라이언트가 더 이상 할 일이 없으면 
                  if ($this->clients[$k]->isFinished()) {
                    //$this->writeLog("client finished:".$this->clients[$k]->getSeq(), __FILE__, __LINE__);
                    $this->clients[$k]->close();
                    unset($this->clients[$k]);
                  }
                  $this->finished++;
                }
                else if ($this->que[$i]['func'] == "scan") {
                  //$this->writeLog("worker found finished:".$this->que[$i]['seq'], __FILE__, __LINE__);
                  $this->clients[$k]->scanFinished();
                }
              }
            }
          }
          $this->que[$i] = null;          
        }
        else $ret++;
      }
    }
    return $ret;
  }

}

// daemon 두번 실행 방지 코드
if (file_exists("/var/run/tmc.pid")) {
  $pid = file_get_contents("/var/run/tmc.pid");
  $command = 'ps -p ' . $pid;
  exec($command, $op);
  if (isset($op[1])) {
    echo "TMC duplicated daemon is not allowed. Aborted".PHP_EOL;
    exit;
  }
  else unlink("/var/run/tmc.pid");
}
// 혹시나 IMagick tmp파일 있으면 삭제
exec("rm -f /tmp/magick-*");

$daemon = new TMC_daemon();
$daemon->writeLog("TMC Client start", __FILE__, __LINE__);

while ($daemon->run()) {
  usleep(100000);
}

$daemon->writeLog("TMC Client quit", __FILE__, __LINE__);
?>
