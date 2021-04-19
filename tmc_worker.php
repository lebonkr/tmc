<?php
ini_set('memory_limit','8G');
date_default_timezone_set('Asia/Seoul');
require_once "tmc.php";
require 'vendor/autoload.php';

define ('MODE', 'REAL'); // or 'DEV'

class imagick_worker extends TMC {
  private $im = array();
  private $ffmpeg;
  private $video;
  private $imageinfo;
  private $profiles;
  public $exit = false;

  // 결과 저장 구조체
  private $result; 

  private $proc;
  private $req;
  private $template;

  function __construct($big = 0) {
    $this->im['hires'] = new Imagick(); // 최초로 읽을 원본 파일의 imagick

    parent::__construct();

    // IMagick 리소스 제한
    $imagick_mem = isset($this->config['imagick_mem']) ? $this->config['imagick_mem'][$big] : 512;
    IMagick::setResourceLimit(imagick::RESOURCETYPE_AREA, 2000000000);
    IMagick::setResourceLimit(imagick::RESOURCETYPE_MEMORY, intval($imagick_mem)*1024*1024);
    IMagick::setResourceLimit(imagick::RESOURCETYPE_MAP, intval($imagick_mem)*2*1024*1024);

    pcntl_signal(SIGTERM, array(&$this, "sig_handler"));
    pcntl_signal(SIGINT, array(&$this, "sig_handler"));
  }

  public function getConfig() {
    return $this->config;
  }

  public function loadRequest($seq) {
    $this->proc = $seq;
    $this->req = json_decode(file_get_contents($this->PROC_DIR.$this->proc), true);
    return $this->req;
  }

  public function loadImage($fn, $ext) {
    unset($this->ffmpeg, $this->video, $this->imageinfo, $this->profiles);
    try {
      if (file_exists($fn)) {
        if ($ext == "mp4") {
          $this->imageinfo['format'] = "MP4";
          // MP4이면 첫번째 프레임을 로드 해둠
          $temp_filename = substr(hash("md5", $fn),0,8).".jpg";
          $options = array('timeout'=>3600, 'ffmpeg.threads'=>1);
          if (isset($this->config['ffmpeg_dir']) && !empty($this->config['ffmpeg_dir'])) {
            if (file_exists($this->config['ffmpeg_dir'].'ffmpeg')) $options['ffmpeg.binaries'] = $this->config['ffmpeg_dir'].'ffmpeg';
            if (file_exists($this->config['ffmpeg_dir'].'ffprobe')) $options['ffprobe.binaries'] = $this->config['ffmpeg_dir'].'ffprobe';
          }
          $this->ffmpeg = FFMpeg\FFMpeg::create($options);

          $this->video = $this->ffmpeg->open($fn);
          
          $second = isset($this->req['hires']['capture']) ? $this->req['hires']['capture'] : 0;
          $this->video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($second))->save($this->TEMP_DIR.$temp_filename);
          $ret = $this->im['hires']->readImage($this->TEMP_DIR.$temp_filename);
          if ($ret) {
            $this->imageinfo['w'] = $this->im['hires']->getImageWidth(); 
            $this->imageinfo['h'] = $this->im['hires']->getImageHeight();
          }
          unlink($this->TEMP_DIR.$temp_filename);
          return $ret;
        }
        else {
          try {
            $this->im['hires']->pingImage($fn);
          } catch (ImagickException $e) {
            $this->writeLog("ERROR: Load image failed($fn):".$e->getMessage(),  __FILE__, __LINE__);
            return false;
          }
          $this->imageinfo['w'] = $this->im['hires']->getImageWidth(); 
          $this->imageinfo['h'] = $this->im['hires']->getImageHeight();
          $configw = isset($this->config['width_threshold']) ? $this->config['width_threshold'] : 2000;
          $configh = isset($this->config['height_threshold']) ? $this->config['height_threshold'] : 2000;
          if ($this->imageinfo['w'] > $configw || $this->imageinfo['h'] > $configh) {
              $fitbyWidth = ($configw / $this->imageinfo['w']) > ($configh / $this->imageinfo['h']);
              $aspectRatio = $this->imageinfo['h'] / $this->imageinfo['w'];
              if ($fitbyWidth) {
                $new_width = $configw;
                $new_height = abs($configh * $aspectRatio);
              } else {
                $new_width = abs($configw / $aspectRatio);
                $new_height = $configh;
              }
              $this->im['hires']->setSize($new_width, $new_height);
          }
          if ($this->im['hires']->readImage($fn)) {
            $this->imageinfo['channels'] = $this->im['hires']->getimagecolorspace();
            $this->imageinfo['format'] = $this->im['hires']->getImageFormat();
            $this->profiles = $this->im['hires']->getImageProfiles("icc", true);
          }
          return true;
        }
      }
      else $this->result['error'] = "File not found : $fn";
      return false;
    } catch (Exception $e){
      $this->result['error'] = $e->getMessage();
      $msg = "ERROR: loadImage failed - ".$this->result['error'];
      $this->writeLog($msg,  __FILE__, __LINE__);
      return false;
    }
  }

  public function writeLog($log, $fn = __FILE__, $line = __LINE__)
  {
    if (MODE == "DEV")
      $text = sprintf("%s [%d][%s:%d] - %s".PHP_EOL, Date("ymdHis"), getmypid(), basename($fn), $line, $log);
    else 
      $text = sprintf("%s [%d] - %s".PHP_EOL, Date("ymdHis"), getmypid(), $log);
    parent::writeLog("TMC_worker.".Date("ymd").".log", $text);
  }

  private function writeDone($procname, $srcname, $stepcount, $created, $warning)
  {
    $text = sprintf("%s\t%d\t%d\t%d\n", $srcname, $stepcount, $created, $warning);
    file_put_contents($this->DONE_DIR.$procname.".result", $text, FILE_APPEND | LOCK_EX);
  }

  private function setDefaultStep($step) {
    // proc의 옵션 기본값 처리
    $step['strip'] = isset($step['strip']) ? $step['strip'] : false;
    $step['cmyk'] = isset($step['cmyk']) ? $step['cmyk'] : false;
    $step['srgb'] = isset($step['srgb']) ? $step['srgb'] : false;
    $step['force_dir'] = isset($step['force_dir']) ? $step['force_dir'] : true;
    $step['overwrite'] = isset($this->req['hires']['overwrite']) ? $this->req['hires']['overwrite'] : (isset($step['overwrite']) ? $step['overwrite'] : false);
    $step['watermark'] = isset($step['watermark']) ? $step['watermark'] : false;
    $step['force_image'] = isset($step['force_image']) ? $step['force_image'] : false;	
    $step['offercomp'] = isset($step['offercomp']) ? $step['offercomp'] : null;
    $step['comp'] = isset($step['comp']) ? $step['comp'] : 0;
    $step['save'] = isset($step['save']) ? $step['save'] : true;
    $step['source_ext'] = isset($step['source_ext']) ? $step['source_ext'] : "";
    $step['rename'] = isset($step['rename']) ? $step['rename'] : "";
    $step['bitrate'] = isset($step['bitrate']) ? $step['bitrate'] : 300;
    $step['ffmpeg'] = isset($step['ffmpeg']) ? $step['ffmpeg'] : false;
    $step['density'] = isset($step['density']) ? $step['density'] : 0;

    return $step;
  }

  private function getWriteFilename($filename, $option) {
    // 파일명을 변경해야 하는 경우 여기서 변경
    if (!empty($option) && is_array($option)) {
      foreach ($option as $search => $replace) {
        if (empty($search) || empty($replace) || !is_string($search) || !is_string($replace)) continue;
        $filename = str_ireplace($search, $replace, $filename);
      }
    }
    return $filename;
  }

  private function changeExt($path, $newext) {
    // ext는 .를 포함하지 않아야 함
    $path_part = pathinfo($path);
    return $path_part['dirname']."/".$path_part['filename'].".".$newext;
  }

  public function loadTemplate() {
    // 템플릿 파일이 있으면 메모리로 로드
    if (file_exists($this->CONFIG_DIR."template.json")) 
      $this->template = json_decode(file_get_contents($this->CONFIG_DIR."template.json"), true);
    else $this->template = null;
  }

  private function getTemplate() {
    // json내의 템플릿이 지정되어 있으면 템플릿을 config 디렉토리에서 읽어서 리턴
    // 아니면 request에서 proc을 찾아서 리턴
    // 없을 경우 NULL 리턴
    if (isset($this->req['template']) && !empty($this->req['template'])) {
      $templatename = $this->req['template']; 
      if (isset($this->template[$templatename]) && !empty($this->template[$templatename]) && isset($this->template[$templatename]['proc']) && !empty($this->template[$templatename]['proc'])) return $this->template[$templatename]['proc'];
    }
    if (isset($this->req['proc']) && !empty($this->req['proc'])) return $this->req['proc'];
    return null;
  }

  private function writeSavedDir($path) {
    $path_part = pathinfo($path);
    if (isset($this->config['tcc']) && isset($this->config['tcc']['min_sec']) && !empty($this->config['tcc']['min_sec'])) {
      file_put_contents($this->TEMP_DIR."tcc_dir", $path_part['dirname']."/".PHP_EOL, FILE_APPEND | LOCK_EX);
    }
  }

  private function callback($callback, $file_name) {
    foreach ($callback as $call) {
      switch ($call["action"]) {
        case "curl":
          $callback_addr = str_replace("%file%", $file_name, $call["target"]);
          $curl = new curl();
          $ret = $curl->call($callback_addr);
          $this->writeLog("callback curl=$callback_addr : ".$ret['result'].json_encode($ret['response']));
          break;
        case "file":
          $cmd = str_replace("%file%", $file_name, $call["cmd"]);
          while (true) {
            $target = str_replace("%datetime%", sprintf("wd%s%04d", date("ymdHis"), rand(1,1000)), $call["target"]);
            if (!file_exists($target)) break;
          }
          file_put_contents($target, $cmd);
          $this->writeLog("callback file=".$cmd);
          break;
      }
    }
  }

  private function callback2($callback_addr, $file_name) {
    $callback_addr = str_replace("%file%", $file_name, $callback_addr);
    $curl = new curl();
    $ret = $curl->call($callback_addr);
    $this->writeLog("curl addr=$callback_addr, result=".json_encode($ret));
  }

  public function doProc($workload, $job) {
    // return value
    // 2 : 모든 단계를 성공
    // 1 : 1개 이상 오류
    $created = 0;
    $warning = 0;
    $step_count = 0;
    $saved_step = array();

    try {
      // 템플릿 로드
      $proc = $this->getTemplate();

      // 기본 필수값 존재하는지 체크
      if (!isset($this->req) || empty($proc) ||  !isset($this->config['site']) || !isset($this->req['hires']['site']) || !isset($this->config['site'][$this->req['hires']['site']]) || !isset($this->req['hires']['path_head']) || !isset($this->req['hires']['path_tail']) || !isset($workload['source'])) {
        $msg = "ERROR: Invalid predefined variables";
        $this->writeLog($msg." - data:".json_encode($workload),  __FILE__, __LINE__);
        throw new Exception($msg);
      }

      $step_count = count($proc) + 1;
      $step_current = 0;
      $job->sendStatus($step_current++, $step_count);

      // 소스 파일 경로
      $src_file = $this->config['site'][$this->req['hires']['site']]['root'].$this->req['hires']['path_head'].$this->req['hires']['path_tail'].$workload['source'];
      // 소스 파일 확장자
      $src_file_ext = pathinfo($src_file, PATHINFO_EXTENSION);
      // 저장할 파일명
      $dst_file = $this->getWriteFilename($workload['source'], isset($this->req['hires']['rename']) ? $this->req['hires']['rename'] : null);
      $offer_type = 0; // 오퍼타입은 한번 조회하고 나면 다시 할 필요 없으므로 미리 선언해둠

      if (empty($dst_file)) {
        $msg = "ERROR: Invalid file name for ".$workload['source'];
        $this->writeLog($msg." - data:".json_encode($step),  __FILE__, __LINE__);
            throw new Exception($msg);
      }
      else if ($this->loadImage($src_file, $src_file_ext)) {
        // 이미지 로드를 성공하면
        $job->sendStatus($step_current++, $step_count);
        // overwrite=false일때 이미지를 만들지 말지에 대한 검사
        $sources = array();
        $hires_use_cnt = 0; // hires 이미지매직 객체를 빨리 지워버리기 위한 카운터
        for ($i = count($proc) -1; $i >= 0; $i--) {
          if ($proc[$i]['source'] == "hires") $hires_use_cnt++;
          else if (!in_array($proc[$i]['source'], $sources)) $sources[] = $proc[$i]['source'];
          $proc[$i]['force_image'] = in_array($proc[$i]['name'], $sources);
        }
        unset($sources);

        // 대량 업로드에서 저해상 원본 만들때 쓰이는 최소 크기 검사
        if (isset($this->req['hires']['min_size'])) {
          if ($this->req['hires']['min_size'][0] < $this->imageinfo["w"] || $this->req['hires']['min_size'][1] < $this->imageinfo["h"]) {
            $this->im['hires']->clear();
            $job->sendStatus($step_current++, $step_count);
            $msg = "SKIP: under min_size - ".$this->imageinfo["w"]."x".$this->imageinfo["h"];
            $this->writeLog($msg,  __FILE__, __LINE__);
            $this->writeDone($workload['proc'], $workload['source'], $step_count -1, $created, $warning);
            return true;
          }
        }

        foreach ($proc as $step) {
          if (!isset($step['name']) || !isset($step['source']) || !isset($step['size']) || !isset($step['path_head'])) {
            $msg = "ERROR: Invalid condition variables";
            $this->writeLog($msg." - data:".json_encode($step),  __FILE__, __LINE__);
            throw new Exception($msg);
          }
          
          $step = $this->setDefaultStep($step);

          // 확장자 체크하는 source_ext 검사
          if (!empty($step['source_ext'])) {
            $not_char = substr($step['source_ext'], 0, 1);

            if ($not_char != "!") {
              if ($step['source_ext'] != $src_file_ext) {
                $job->sendStatus($step_current++, $step_count);
                continue;
              }
            }
            else {
              $exclude_source_ext = substr($step['source_ext'], 1, strlen($step['source_ext'])-1);
              if ($exclude_source_ext == $src_file_ext) {
                $job->sendStatus($step_current++, $step_count);
                continue;
              }
            }
          }

          try {
            // 타겟부터 체크
            if (isset($this->req['target'])) {
              // proc.target 이 있으면 저장을 원본과 다른 별도 서버에 할경우이다 (for test)
              $dst_dir = $this->req['target']['root'].$step['path_head'].$this->req['hires']['path_tail'];
            }
            else {
              $dst_dir = $this->config['site'][$this->req['hires']['site']]['root'].$step['path_head'].$this->req['hires']['path_tail'];
            }

            $act = array("ST"=>false, "CM"=>false, "SR"=>false, "RS"=>false, "SP"=>false, "WM"=>false, "CP"=>false, "FD"=>false, "OW"=>false, "SV"=>false, "FF"=>false );

            $writecheck = true;
            if (file_exists($dst_dir.$dst_file) || ($step['ffmpeg'] && file_exists($this->changeExt($dst_dir.$dst_file, "jpg")))) {
              if (!$step['overwrite']) {
                // 파일이 있고 덮어쓰기 하지 말라 했으니 에러가 아님
                // warning은 에러일때만 증가 -> 아래에서 덮어쓸때 못지우면 에러임
                $msg = "WARN: overwrite false but exists";
                $writecheck = false;
                // 이 단계가 나중에 필요한 단계가 아니면 스킵
                if (!$step['force_image']) {
                  $job->sendStatus($step_current++, $step_count);
                  continue;
                }
              }
            }

            $sourceBlob = $this->im[$step['source']]->getImageBlob();
            if (empty($sourceBlob)) {
              $this->im[$step['name']] = clone $this->im[$step['source']];
            }
            else  {
              $this->im[$step['name']] = new Imagick();
              $this->im[$step['name']]->readImageBlob($sourceBlob);
            }
            if ($step['source'] == "hires") $hires_use_cnt--;
            if ($hires_use_cnt < 1) $this->im['hires']->clear();
              
            // strip 처리
            if ($step['strip']) {
              $this->im[$step['name']]->stripImage();
              if (!empty($this->profiles)) 
                $this->im[$step['name']]->profileImage("icc", $this->profiles['icc']);
              $act["SR"] = true;
            }

            // cmyk 체크
            if ($step['cmyk']) {
              if ($this->imageinfo['channels'] == Imagick::COLORSPACE_CMYK) {
                if (isset($this->config['site'][$this->req['hires']['site']]['cmyk']) && is_array($this->config['site'][$this->req['hires']['site']]['cmyk'])) {
                  foreach ($this->config['site'][$this->req['hires']['site']]['cmyk'] as $one) {
                    if (file_exists($this->config.$one)) {
                      $icc_content = file_get_contents($this->config.$one);
                      $this->im[$step['name']]->profileImage('icc', $icc_content);
                      unset($icc_content);
                      $act["CM"] = true;
                    }
                    else {
                      $msg = "WARN: CMYK profile dost not exists";
                      $this->writeLog($msg." - file:".$this->config.$one,  __FILE__, __LINE__);
                      $warning++;
                    }
                  }
                }
              }
            }

            // sRGB 체크
            if ($step['srgb']) {
              if (isset($this->config['site'][$this->req['hires']['site']]['srgb']) && is_array($this->config['site'][$this->req['hires']['site']]['srgb'])) {
                foreach ($this->config['site'][$this->req['hires']['site']]['srgb'] as $one) {
                  if (file_exists($this->CONFIG_DIR.$one)) {
                    $icc_content = file_get_contents($this->CONFIG_DIR.$one);
                    $this->im[$step['name']]->profileImage('icc', $icc_content);
                    unset($icc_content);
                    $act["SR"] = true;
                  }
                  else {
                    $msg = "WARN: sRGB profile dost not exists";
                    $this->writeLog($msg." - file:".$this->CONFIG_DIR.$one,  __FILE__, __LINE__);
                    $warning++;
                  }
                }
              }
            }

            $SourceW = $this->imageinfo["w"];
            $SourceH = $this->imageinfo["h"];
            $newx = $SourceW;
            $newy = $SourceH;

            // resize 처리
            if ($step['size'][0] != 0 || $step['size'][1] != 0) {
              if ($step['ffmpeg']) {
                if (empty($this->video)) {
                  $msg = "WARN: ffmpeg is true but video is null";
                  $this->writeLog($msg." - file:".$dst_file,  __FILE__, __LINE__);
                }
                else {
                  $newx = $step['size'][0];
                  $newy = $step['size'][1];
                  // size 값이 0,0이면 스킵, 아니면 리사이즈
                  if ($step['size'][0] != 0 && $step['size'][1] != 0) {
                    // 이 경우에는 가로 세로비율을 따져봐야 함
                    if ($SourceW / $SourceH > $step['size'][0] / $step['size'][1]) {
                      // 리사이즈 영역보다 원본이 가로가 더 넓으면 넓이에 맞춤
                      $newy = Round($SourceH * $step['size'][0] / $SourceW);
                    }
                    else {
                      // 아니면 높이에 맞춤
                      $newx = Round($SourceW * $step['size'][1] / $SourceH);
                    }
                  }
                  else if ($step['size'][0] == 0) {
                    // 가로 값이 0이면
                    $newx = Round($SourceW * $step['size'][1] / $SourceH);
                  }
                  else {
                    // 세로 값이 0이면
                    $newy = Round($SourceH * $step['size'][0] / $SourceW);
                  }
                }
              }
              else {
                $newx = $step['size'][0];
                $newy = $step['size'][1];
                // size 값이 0,0이면 스킵, 아니면 리사이즈
                if ($step['size'][0] != 0 && $step['size'][1] != 0) {
                  // 이 경우에는 가로 세로비율을 따져봐야 함
                  if ($SourceW / $SourceH > $step['size'][0] / $step['size'][1]) {
                    // 리사이즈 영역보다 원본이 가로가 더 넓으면 넓이에 맞춤
                    $newy = 0;
                  }
                  else {
                    // 아니면 높이에 맞춤
                    $newx = 0;
                  }
                }
                
                $this->im[$step['name']]->resizeImage($newx, $newy, imagick::FILTER_LANCZOS, 1);
                $act["RS"] = true;
              }
            }

            // sharpen 처리
            if (isset($step['sharpen'])) {
              $radius = 0; $sigma = 0;
              if (count($step['sharpen']) == 2) {
                $radius = $step['sharpen'][0]; 
                $sigma = $step['sharpen'][1];
              }
              else $sigma = $step['sharpen'][0];
              $this->im[$step['name']]->adaptiveSharpenImage($radius, $sigma);
              $act["SP"] = true;
            }

            // watermark 처리
            if ($step['watermark'] && !$step['ffmpeg']) {
              // mp4에 대한 watermark는 저장할때 같이 함
              if (!file_exists($this->CONFIG_DIR.$this->config['site'][$this->req['hires']['site']]['watermark'])) {
                $msg = "WARN: Watermark file dost not exists";
                $this->writeLog($msg." - file:".$this->CONFIG_DIR.$this->config['site'][$this->req['hires']['site']]['watermark'],  __FILE__, __LINE__);
                $warning++;
              }
              else {
                $im_wm = new Imagick($this->CONFIG_DIR.$this->config['site'][$this->req['hires']['site']]['watermark']);

                $iWidth = $this->im[$step['name']]->getImageWidth();
                $iHeight = $this->im[$step['name']]->getImageHeight();
                $wWidth = $im_wm->getImageWidth();
                $wHeight = $im_wm->getImageHeight();

                if ($iHeight < $wHeight || $iWidth < $wWidth) {
                    // resize the watermark
                    if ($iWidth < $wWidth) {
                      $wHeight = floor($wHeight * ($iWidth / $wWidth));
                      $wWidth = $iWidth;
                    }
                    else {
                      $wWidth = floor($wWidth * ($iHeight / $wHeight));
                      $wHeight = $iHeight;
                    }
                    $im_wm->scaleImage($wWidth, $wHeight);
                }

                // calculate the position
                $x = floor(($iWidth - $wWidth) / 2);
                $y = floor(($iHeight - $wHeight) / 2);

                $this->im[$step['name']]->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
                $this->im[$step['name']]->compositeImage($im_wm, imagick::COMPOSITE_OVER, $x, $y);
                $act["WM"] = true;
                $im_wm->clear();
              }
            }

            // compression 처리 
            $comprate = 0;
            if (!empty($step['comp'])) {
              $comprate = intval($step['comp']);
            }
           
            if ($comprate > 50 && $comprate < 100) {
              // 압축율이 50 밑으로 갈리 없고 100이면 할필요 없음
              $this->im[$step['name']]->setImageCompression(Imagick::COMPRESSION_JPEG);
              $this->im[$step['name']]->setImageCompressionQuality($comprate);
              $act["CP"] = true;
            }

            // forcedir 처리
            $real_dir = dirname($dst_dir.$dst_file);
            if ($step['force_dir'] && !is_dir($real_dir)) {
              if (!mkdir($real_dir, 0775, true) && !is_dir($real_dir)) {
                // is_dir을 다시 검사하는 이유는 다른 프로세스에서 생성해버리면 생성 오류가 발생하기 때문
                $msg = "ERROR: Directory create failed - ".$real_dir;
                $this->writeLog($msg,  __FILE__, __LINE__);
                throw new Exception($msg, -1);
              }
              $act["FD"] = true;
            }

            if ($writecheck && file_exists($dst_dir.$dst_file)) {
              // overwrite = true이고 목적파일이 존재하더라도 파일 크기가 같으면 같은 파일로 간주(다른 이미지가 같은 사이즈로 변환될 가능성이 얼마나 되겠어?)하고 저장 안함
              $temp_image_blob = $this->im[$step['name']]->getImageBlob();
              $sameskip = isset($this->config['site']['samesizeskip']) ? $this->config['site']['samesizeskip'] : true;
              if ($sameskip && filesize($dst_dir.$dst_file) == $this->im[$step['name']]->getImageLength()) {
                $writecheck = false;
                $msg = "SKIP: filesize same - ".$step['name'].$dst_file;
                $this->writeLog($msg,  __FILE__, __LINE__);
              }
              else {
                // 목적 파일이 있고 overwrite = false인 경우는 이미 위에서 체크하고 벗어남
                // 따라서 목적 파일이 있으면 무조건 삭제하면 됨
                if (unlink($dst_dir.$dst_file)) {
                  $act["OW"] = true;
                }
                else {
                  $writecheck = false;
                  $msg = "WARN: File exist but delete failed - ".$dst_file;
                  $this->writeLog($msg,  __FILE__, __LINE__);
                  $warning++;
                }
              }
              unset($temp_image_blob);
            }

            if ($writecheck && $step['save']) {
              // 저장
              if ($step['ffmpeg']) {
                if (empty($this->video)) {
                  $msg = "WARN: ffmpeg is true but video is null";
                  $this->writeLog($msg." - file:".$dst_file,  __FILE__, __LINE__);
                }
                else {
                  if ($step['size'][0] != 0 || $step['size'][1] != 0) {
                    // reisze
                    $this->video->filters()->resize(new FFMpeg\Coordinate\Dimension($newx, $newy));
                    $act["RS"] = true;
                  }
                  if ($step['watermark']) {
                    $im_wm = new Imagick($this->CONFIG_DIR.$this->config['site'][$this->req['hires']['site']]['watermark']);
                    $wWidth = $im_wm->getImageWidth();
                    $wHeight = $im_wm->getImageHeight();
                    $im_wm->clear();
                    if ($wWidth > $newx) $left_cond = '(overlay_w-main_w)/2';
                    else $left_cond = '(main_w-overlay_w)/2';
                    if ($wHeight > $newy) $top_cond = '(overlay_h-main_h)/2';
                    else $top_cond = '(main_h-overlay_h)/2';

                    $this->video->filters()->watermark($this->CONFIG_DIR.$this->config['site'][$this->req['hires']['site']]['watermark'], array('position' => 'relative', 'left' => $left_cond, 'top' => $top_cond))->synchronize();
                    $act["WM"] = true;
                  }
                  $codec = new FFMpeg\Format\Video\X264('aac','libx264');
                  $codec->setKiloBitrate($step['bitrate']);
                  $this->video->save($codec, $dst_dir.$dst_file);
                  $this->writeSavedDir($dst_dir.$dst_file);
                  $act["FF"] = true;
                  $act["SV"] = true;
                  $saved_step[] = $step['name'];
                  unset($codec);
                  $created++;
                }
              }
              else {
                // 저해상 원본에서 쓰이는 해상도 설정
                if (isset($this->req['hires']['density'])) 
                  $this->im[$step['name']]->setImageResolution($this->req['hires']['density'], $this->req['hires']['density']);
                else if (!empty($step['density']))
                  $this->im[$step['name']]->setImageResolution($step['density'], $step['density']);

                // PNG면 배경처리 해야함
                if ($this->imageinfo['format'] == "PNG") {
                  // png 추가 처리. 배경 무늬를 읽고
                  $transparent_block = new Imagick($this->CONFIG_DIR.'16X16.png');
                  // 이미지 조합할 임시 이미지를 원본 크기에 맞게 생성
                  $output = new Imagick();
                  $output->newimage($this->im[$step['name']]->getImageWidth(), $this->im[$step['name']]->getImageHeight(), 'transparent');
                  // 포맷을 jpg로 바꾸고
                  $output->setImageFormat('jpg');
                  // 배경을 깐 다음
                  $output = $output->textureImage($transparent_block);
                  // 원본을 위에 얹자
                  $output->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
                  $output->compositeImage($this->im[$step['name']], imagick::COMPOSITE_OVER, 0, 0);
                  // 원본 지우고
                  $this->im[$step['name']]->clear();
                  // 임시를 원본으로 카피
                  $this->im[$step['name']]->readImageBlob($output->getImageBlob());
                  // 임시를 지우자
                  $output->clear();
                  $transparent_block->clear();
                }
                // 원본 파일이 jpg뿐 아니라 mp4도 가능하므로 이미지 저장시에는 확장자를 jpg로 고정해버려야 함
                if (!$this->im[$step['name']]->writeImage($this->changeExt($dst_dir.$dst_file, "jpg"))) {
                  $msg = "ERROR: File save failed - ".$this->changeExt($dst_dir.$dst_file, "jpg");
                  $this->writeLog($msg,  __FILE__, __LINE__);
                  throw new Exception($msg, -1);
                }
                else {
                  $this->writeSavedDir($dst_dir.$dst_file, "jpg");
                  $act["SV"] = true;
                  $saved_step[] = $step['name'];
                  $created++;
                }
              }
            }
            $act_str = array();
            foreach ($act as $key => $ok) if ($ok) $act_str[] = $key;
            if (isset($step['callback']) && !empty($step['callback']) && $act["SV"]) {
              // 개별 콜백 
              $this->callback($step['callback'], $dst_file);
            }
          }
          catch (Exception $e){
            $warning++;
            $this->writeLog(sprintf("EXCEPTION:[%d]%s", $e->getCode(), $e->getMessage()),  __FILE__, __LINE__);
            $this->result['error'] = $e->getMessage();
            if ($e->getCode() < 0) {
              $this->writeDone($workload['proc'], $workload['source'], $step_count -1, $created, $warning);
              foreach ($proc as $step2) {
                if (isset($this->im[$step2['name']]) && !empty($this->im[$step2['name']])) $this->im[$step2['name']]->clear();
              }
              if (!empty($this->im['hires'])) $this->im['hires']->clear();
              return false;
            }
          }
          $job->sendStatus($step_current++, $step_count);
        }
        
        if (count($saved_step) > 0) 
          $this->writeLog(sprintf($dst_file." saved:%s", implode(",",$saved_step)),  __FILE__, __LINE__);
        foreach ($proc as $step2) {
          if (isset($this->im[$step2['name']]) && !empty($this->im[$step2['name']])) $this->im[$step2['name']]->clear();
        }
      }
      else {
        $msg = "ERROR: Source file load failed - ".$src_file;
        $this->writeLog($msg,  __FILE__, __LINE__);
        throw new Exception($msg, -1);      
      }
    }
    catch (Exception $e){
      $warning++;
      $this->writeLog(sprintf("EXCEPTION:[%d]%s", $e->getCode(), $e->getMessage())  , __FILE__, __LINE__);
      $this->result['error'] = $e->getMessage();
      $this->writeDone($workload['proc'], $workload['source'], $step_count -1, $created, $warning);
      if (!empty($this->im['hires'])) $this->im['hires']->clear();
      return false;
    }
    $this->writeDone($workload['proc'], $workload['source'], $step_count -1, $created, $warning);
    if (!empty($this->im['hires'])) $this->im['hires']->clear();
    return true;
  }

  public function convert($job)
  {
    $workload_json = $job->workload();
    $workload_size = $job->workloadSize();

    $workload = json_decode($workload_json, true);

    $this->loadRequest($workload['proc']);

    $this->writeLog("Received job: convert " . $job->handle(),  __FILE__, __LINE__);
    $this->writeLog("Workload: $workload_json ($workload_size)",  __FILE__, __LINE__);

    $result= $this->doProc($workload, $job);
    return $result;
  }

  public function bigconvert($job)
  {
    $workload_json = $job->workload();
    $workload_size = $job->workloadSize();

    $workload = json_decode($workload_json, true);

    $this->loadRequest($workload['proc']);

    $this->writeLog("Received job: bigconvert " . $job->handle(),  __FILE__, __LINE__);
    $this->writeLog("Workload: $workload_json ($workload_size)",  __FILE__, __LINE__);

    $result= $this->doProc($workload, $job);
    return $result;
  }

  private function checkFilename($path_file, $head_dir, $include_array, $exclude_array) {
    // 파일이름에서 head_path떼고 파일명에 include exclude 체크 후 대상 파일이면 경로+파일명을, 아니면 null을 리턴하는 함수
    $basename = str_replace($head_dir, "", $path_file);
    // include 검사
    $include = false;
    foreach ($include_array as $match) {
      if (fnmatch($match, $basename)) {
        $include = true;
        break;
      }
    }
    // exclude 검사
    if ($include) {
      foreach ($exclude_array as $match) {
        if (fnmatch($match, $basename)) {
          $include = false;
          break;
        }
      }
    }
    if ($include) return $basename;
    return null;
  }

  public function scan($job) {
    $workload_json = $job->workload();
    $workload_size = $job->workloadSize();

    $workload = json_decode($workload_json, true);

    $this->loadRequest($workload['proc']);

    $this->writeLog("Received job: scan " . $job->handle(),  __FILE__, __LINE__);
    $this->writeLog("Workload: $workload_json ($workload_size)",  __FILE__, __LINE__);

    $ret = 0;
    if (isset($this->req['hires']['site']) && isset($this->config['site'][$this->req['hires']['site']])) {
      $workload['path'] = $this->config['site'][$this->req['hires']['site']]['root'].$this->req['hires']['path_head'].$this->req['hires']['path_tail'];
      $workload['subdir'] = $this->req['hires']['subdir'];
      $ret = $this->scanRecursive($workload);
      file_put_contents($this->PROC_DIR.$this->proc.".stat", $ret);
    }
    $this->writeLog("scan ret=$ret",  __FILE__, __LINE__);
    return $ret;
  }

  private function scanRecursive($workload) {
    // input :  workload = array (path, subdir), job 
    // 초기에는 파일 리스트를 어레이로 리턴하였으나, 갯수가 많아질 경우 메모리 오버플로우가 일어나기 때문에 파일로 저장하는 루틴으로 변경함
    // 리턴값은 어레이에서 총 갯수로 변경
    // /proc/this->proc.files 파일로 저장
    $ret = 0;
    if (empty($workload['path'])) return $ret;
    $lists = @scandir($workload['path']);

    if(!empty($lists)) {
      foreach($lists as $f) {
        if(is_dir($workload['path'].DIRECTORY_SEPARATOR.$f) && $f != ".." && $f != ".") {
          if ($workload['subdir']) {
            $ret += $this->scanRecursive(array('path' => $workload['path'].DIRECTORY_SEPARATOR.$f, 'subdir' => true));
          }
        }
        else {
          $fname = $this->checkFilename($workload['path'].DIRECTORY_SEPARATOR.$f, $this->config['site'][$this->req['hires']['site']]['root'].$this->req['hires']['path_head'].$this->req['hires']['path_tail'], $this->req['hires']['include'], $this->req['hires']['exclude']);
          if (!empty($fname)) {
            file_put_contents($this->PROC_DIR.$this->proc.".file", $fname."\n", FILE_APPEND);
            $ret++;
          }
        }
      }
    }
    return $ret;
  }

  public function watchdog($job) {
    $workload_json = $job->workload();
    $workload_size = $job->workloadSize();

    $workload = json_decode($workload_json, true);
    $this->proc = $workload['proc'];

    $this->writeLog("Received job: watchdog (".$workload['site'].")" . $job->handle(),  __FILE__, __LINE__);
    $this->writeLog("Workload: $workload_json ($workload_size)",  __FILE__, __LINE__);
    
    $ret = 0;
    if (isset($workload['site']) && isset($this->config['site'][$workload['site']])) {
      $workload['path'] = $this->config['site'][$workload['site']]['root'].$this->config['watchdog'][$workload['site']]['dir'];
      $workload['curdepth'] = 0;
      $minsec = isset($this->config['watchdog'][$workload['site']]['min_sec']) ? $this->config['watchdog'][$workload['site']]['min_sec'] : 1200;
      $maxsec = isset($this->config['watchdog'][$workload['site']]['max_sec']) ? $this->config['watchdog'][$workload['site']]['max_sec'] : 86400;
       
      $now = time() - 5*60; // 5분 전이 기준시간임
      $lasttime = trim(file_get_contents($this->CONFIG_DIR.$workload['site'].".watchdog"));
      if (empty($lasttime)) $lasttime = $now - 60*60*24*30; // 이 파일이 없으면 한달 전부터 기준으로 탐색

      if ($now - $lasttime > $maxsec) $now = $lasttime + $maxsec; // 마지막 탐색시간이 최대 시간보다 전이면 기준시간을 마지막 탐색 시간 + 최대 시간으로 조정함 (한꺼번에 너무 많이 탐색하지 않도록 max_sec 만큼만 하도록 조정함)
      if ($now - $lasttime >= $minsec) {  // 마지막 실행시간에서 최소 시간이 지났으면
        $workload['lasttime'] = $lasttime;
        $workload['now'] = $now;

        $this->writeLog("watchdog run scan=".json_encode($workload),  __FILE__, __LINE__);

        $ret = $this->scanWatchdog($workload);
        if (!empty($ret)) {
          file_put_contents($this->PROC_DIR.$this->proc.".stat", $ret);
        }
        file_put_contents($this->CONFIG_DIR.$workload['site'].".watchdog", $workload['now']);
      }
    }
    $this->writeLog("watchdog ret=$ret",  __FILE__, __LINE__);
    return $ret;
  }

  private function scanWatchdog($workload) {
    $ret = 0;
    if (empty($workload['path'])) return $ret;
    $lists = @scandir($workload['path']);

    if(!empty($lists)) {
      foreach($lists as $f) {
        if ($f == ".." || $f == ".") continue;
        $mtime = filemtime($workload['path'].DIRECTORY_SEPARATOR.$f);
        $isdir = is_dir($workload['path'].DIRECTORY_SEPARATOR.$f);

        if (!$isdir && $mtime > $workload['lasttime'] && $mtime <= $workload['now']) {
          $fname = $this->checkFilename($workload['path'].DIRECTORY_SEPARATOR.$f, $this->config['site'][$workload['site']]['root'].$this->config['watchdog'][$workload['site']]['dir'], $this->config['watchdog'][$workload['site']]['include'], $this->config['watchdog'][$workload['site']]['exclude']);
          if (!empty($fname)) {
            file_put_contents($this->PROC_DIR.$this->proc.".file", $fname."\n", FILE_APPEND);
            $ret++;
          }
        }
        if($isdir) {
          if ($this->config['watchdog'][$workload['site']]['mindepth'] > $workload['curdepth'] || $mtime > $workload['lasttime']) {
            $subworkload = $workload;
            $subworkload['path'] = $workload['path'].DIRECTORY_SEPARATOR.$f;
            $subworkload['curdepth']++;
            $ret += $this->scanWatchdog($subworkload);
          }
        }
      }
    }
    return $ret;
  }

  public function sig_handler($sigNo) {
    if ($sigNo == SIGTERM || $sigNo == SIGINT) {
      $this->writeLog("SIGINT got($sigNo)", __FILE__, __LINE__);
      $this->exit = true;
    }
  }
}

//big worker check
$big_worker = 0;
if ($argc > 1) {
  if (is_Numeric($argv[1])) $big_worker = intval($argv[1]);
}

$worker = new imagick_worker($big_worker);
$worker->loadTemplate();
$worker->writeLog("Worker started!",  __FILE__, __LINE__);

if ($argc > 1) {
  // 개별 디버깅용 코드
  if (is_string($argv[1])) {
    $workload = json_decode($argv[1], true);
    if (is_array($workload) && !empty($workload)) {
      class testjob {
        function __construct() { echo "debug mode".PHP_EOL; }
        public function sendStatus($i, $j) {
          echo date("ymdHis")." sendStatus - $i,$j".PHP_EOL;
        }
      }
      $testjob = new testjob();
      $worker->loadRequest($workload['proc']);
      $worker->doProc($workload, $testjob );
      exit;
    }
  }
}

$gmworker= new GearmanWorker();
$gmworker->addServer();
$gmworker->addFunction("convert", array($worker, "convert"));
if ($big_worker == 1) {
  $worker->writeLog("Worker is BIG",  __FILE__, __LINE__);
  $gmworker->addFunction("bigconvert", array($worker, "bigconvert"));
}
$gmworker->addFunction("scan", array($worker, "scan"));
$gmworker->addFunction("watchdog", array($worker, "watchdog"));
$gmworker->setTimeout(1000);

while(true)
{
  pcntl_signal_dispatch();
  if ($worker->exit) break;
  $ret = $gmworker->work();
  if ($ret) {
    if ($gmworker->returnCode() == GEARMAN_TIMEOUT) usleep(1000);
    else if ($gmworker->returnCode() != GEARMAN_SUCCESS)
    {
      $worker->writeLog("return_code: " . $gmworker->returnCode(),  __FILE__, __LINE__);
      break;
    }
  }
  else {
    if ($gmworker->returnCode() == GEARMAN_TIMEOUT) {
    }
    else break;
  }
}
$gmworker->unregisterAll();
$worker->writeLog("Worker terminated!",  __FILE__, __LINE__);
?>
