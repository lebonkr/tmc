# TMC (Thumbnail Multi-process Converter)

## 개요
* PHP상에서 Multi-Process로 동시에 여러개의 이미지의 변환 : gearman 사용
* 이미지 변환 기능은 php-pecl-imagick 사용 : Resize, Sharpen, Compression, Profile, Watermark등
* mp4 동영상에서 프레임 추출, 추출된 이미지 변환 지원
* png 투명 배경 이미지 처리 지원
* REST API 지원, 작업 요청/제어/상태를 API로 함

## 요구사항
* 필수
  * OS : CentOS 7 이상
  * PHP ≥ 5.4.x
  * PHP Library : php-pecl-imagick, php-pecl-gearman
  * gearman ≥ 1.1.12
* 옵션
  * ffmpeg ≥ 3.4.8
  * REST API 사용하려면 Apache 또는 nginx 필요

## 설치 가이드 (CentOS 7.9기준)
* Apache 또는 nginx DocumentRoot = /opt/tmc/api

* install php
```
yum install php php-pecl-imagick php-pecl-gearman 
```

* install gearmand 
```
yum install gearmand
service gearmand start
```

* install ffmpeg
  * install composer
```
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '756890a4488ce9024fc62c56153228907f1545c228516cbf63f885e036d37e9a59d27d63f46af1d4d07ee0f76181c7d3') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
cp composer.phar /usr/local/bin/
ln -s /usr/local/bin/composer.phar /usr/local/bin/composer
```
  * install php-ffmpeg 
```
composer require php-ffmpeg/php-ffmpeg
```
  * install ffmpeg
```
yum install epel-release
yum localinstall --nogpgcheck https://download1.rpmfusion.org/free/el/rpmfusion-free-release-7.noarch.rpm
yum install ffmpeg ffmpeg-devel
```

## TMC 설치
* install directory : /opt/tmc
* git clone 후
`./install.sh`

## TMC 제어
* 데몬 실행
```
service tmc start
```
* 데몬 재시작
```
service tmc restart
```
* 데몬 상태
```
service tmc status
```
* 응답
```
TMC status:running [2021-03-16 14:34:42]
(working/running) workers : (0/20)
Total processed : 98
```
* 데몬 종료 : 실행중이던 모든 프로세스는 정지되고 나서 종료됨. (별다른 조치가 없이) 재개하면 정지되었던 프로세스는 다시 시작됨.
```
service tmc stop
```
* 데몬 정보
  * pid 파일 : /var/run/tmc.pid
  * 실행 로그 : /var/log/tmc.log
  * client 로그 : /opt/tmc/log/client.log
  * worker 로그 : /opt/tmc/log/worker.log

## 시스템 설명
* 시스템 구조

![Architecture](/doc/tmc_arch.png)

용어 | 설명
------------ | -------------
TMC Daemon | 전체 프로세스를 제어하는 객체
TMC Client | 변환 작업을 정의하여 gearman으로 요청을 전송하는 객체
TMC Worker | gearman으로부터 작업을 받아 실제 변환 작업을 하는 객체

* 파일 설명
 

path | 설명
------------ | -------------
README.md | 설명 파일
tmc | 실행 스크립트, /etc/init.d/로 복사됨
install.sh | 설치 스크립트
tmc.php | 공용 라이브러리 파일
tmc_daemon.php | tmc 데몬 및 클라이언트 소스
tmc_worker.php | tmc 워커 실행 소스

## 사용방법
1. 전체 시스템 설정, 성능, 경로 등의 조건을 아래의 설정 가이드를 참고하여 config/config.json에 설정한다. 가장 상위 작업 단위의 구분을 site 항목 밑으로 구별하여 복수개 설정할수 있다.
2. 세부 이미지 변환 작업에 대한 설정은 config/template.json에서 site 단위에서 실행될 변환 작업을 정의한다.
3. gearmand 를 실행한다
4. 상단 설치 가이드에 따라 웹서버를 설정하고 실행한다
5. 아래의 API를 참고하여 job을 정의하고 호출한다
   * 또는 아래의 watchdog 모드를 이용하여 변환할수 있다
6. 로그를 확인하고 변환된 결과물을 확인한다

## 설정 가이드
1. config.json : TMC 전체의 설정값들을 지정함
위치 : /opt/tmc/config/
```Javascript
{
  "client_cnt": 10,		// 클라이언트 최대 갯수이며, 동시에 실행할수 있는 작업 최대 갯수. worker_cnt보다 같거나 작게 설정하면 됨 (권장값 worker_cnt / 2). 만약, 같을 경우 클라이언트마다 1개의 작업만 가능하게 됨
  "worker_cnt": 20, 	// worker에게 넘겨줄 큐 사이즈. 시스템 코어 - 2 정도가 적당한 것으로 추정됨
  "sleep_delay": 100,	// 데몬 모드에서는 하나의 처리를 하고 0.1초 쉬도록 되어 있음. 그 외에 작업을 추가하거나 watchdog에 추가할 경우 sleep할 시간을 정함. microsecond 단위라 1000000이 최대값이며 1초임. (권장 100)
  "ffmpeg_dir": "/usr/bin/",	// ffmpeg와 ffprobe가 설치된 디렉토리. 이 값이 없을 경우 PATH에서 지정되어야 함
  "imagick_mem": [2048, 8196],  // imagick 메모리 제한. 해상도가 10000 x 10000 이상되는 파일들의 경우 readimage만으로도 4G이상을 점유함 
  "big_worker": 3,		// big file을 처리할 워커 갯수. big file 기준은 10000 x 10000 이상. 이 값은 system memeory / 2 > big_worker * 8G 를 추천함
  "skipsamesize": true,	// 크기가 같은 파일이 되면 덮어쓰지 않고 스킵함
  "site" : { // 사이트 기본 정보. 이 값은 작업을 지정할 workload에 사용됨
    "site01" : {
      "root" : "/data/site01",	// 설치된 서버에서 원본의 루트 디렉토리를 지정해야 함
      "cmyk" : [ "USWebCoatedSWOP.icc", "AdobeRGB1998.icc" ], // CMYK 지정에 사용할 icc 프로파일 파일명. config 디렉토리 밑에 저장하고 이름을 지정하면 됨
      "srgb" : [ "sRGB.icm" ],	// RGB 지정에 사용할 icm 프로파일 파일명. config 디렉토리 밑에 저장하고 이름을 지정하면 됨
      "watermark" : "sample.png"  // 워터마크에 사용할 이미지. config 디렉토리 밑에 저장하면 됨
    },
    "site02" : {
      "root" : "/data/site02",
      "cmyk" : [ "USWebCoatedSWOP.icc", "AdobeRGB1998.icc" ],
      "srgb" : [ "sRGB.icm" ],
      "watermark" : "sample.png"
    }
  },
  "watchdog": {	      // watchdog 모드에서 사용할 값
    "site01": {	      // site의 키 값과 일치해야 함
      "dir": "/source",	      // site.root 밑에서 이 디렉토리를 찾아감
      "mindepth": 2,	      // 기본적으로 탐색할 최소 depth. 단순 디렉토리만 포함한 디렉토리는 datetime이 바뀌지 않기 때문에 실제 파일이 존재하는 디렉토리까지 찾을 값을 지정해줘야 함
      "include":["*.jpg","*.png","*.mp4"],      // watchdog에서 포함할 파일 지정
      "exclude":["*_l.jpg"],               // watchdog에 포함되지 않아야 할 파일 지정
      "rename" : {"h.mp4":".mp4","v.mp4":".mp4"},  // 동영상 조건. 작업파일을 만들때 파일 이름을 변경하여 저장함
      "overwrite":true,                   // 덮어쓰기 기본 조건. 이 값이 있으면 템플릿 조건을 override함
      "capture":3,                        // 동영상 기본조건. 이 값이 있으면 템플릿 조건을 override함
      "min_sec":1200,                     // watchdog이 실행하기 위해 필요한 최소시간. 너무 자주 하지 않기 위한 설정값
      "max_sec":86400,                    // watchdog이 탐색할 최대 시간. 너무 많은걸 한꺼번에 하지 않기 위한 설정값
      "template":"it_hires_new"           // watchdog에서 찾은 이미지를 실행할 템플릿명
    }
  }
}
```
2. template.json : 원래 작업파일에서 proc으로 작업 내역을 지정하였으나, 대부분의 경우 각 케이스마다 고정된 값을 사용하면 되기 때문에 템플릿 으로 분리하고 작업 파일에서는 템플릿 이름을 사용하는 것으로 변경
위치 : /opt/tmc/config/
```
{
    "템플릿명": {
      "description": "템플릿 설명",
      "proc": [             // (필수) 실제 변환에 필요한 단계 지정. 복수개 지정 가능함
        {
          "name": "Origin", // (필수) 단계 지시자. 유니크하면 됨. 다른 단계에서 사용됨
          "source": "hires",    // (필수) 원본을 가져올 경우 hires 를 사용하면 됨. hires는 원본을 뜻하는 예약 지시자이며, API로 요청하는 작업 파일에서 정의됨 
          "strip": true,        // (옵션) 변환시 이미지 헤더, ITCP, 태그 정보 등을 없애려면 true, 유지하려면 false, 기본값은 true
          "cmyk": true,         // (옵션) cmyk 프로파일 지정하려면 true, 기본값은 true
          "srgb": true,         // (옵션) rgb 프로파일을 지정하려면 true, 기본값은 true
          "size": [
            1000,1000           // (필수) 리사이즈할 크기. 둘다 0 이상이면 그 크기 내에서 비율을 유지하며 리사이즈. 하나가 0이면 나머지 값으로 크기 지정. 둘다 0이면 리사이즈 하지 않음
          ],
          "path_head": "/site01/Destination",  // (필수) 변환된 이미지를 저장할 최상위 디렉토리. 저장할 root는 config.json에 지정된 root임. 나머지는 hires.path_tail을 붙여서 저장할것임
          "force_dir": true,    // (옵션) 디렉토리가 없을때 생성해서 저장하려면 true, 아니면 디렉토리가 없으면 저장하지 않음. true가 기본값임
          "overwrite": true,    // (옵션) 기존 동일 파일이 있을때 덮어쓰려면 true. false가 기본값임
          "watermark": true,    // (옵션) 워터마크 지정 여부. config에 설정된 워터마크를 사용함. false가 기본값임
          "sharpen": [          // (옵션) 샤픈 필터 지정값. 첫번째가 Radius, 두번째가 Gamma. 없으면 샤픈 하지 않음
            4,0.4
          ],
          "comp": 80,           // (옵션) 원본 압축 비율. 0이면 압축하지 않음, 기본값은 0
          "save": true          // (옵션) 저장을 하지 않을 경우 false로 요청, 기본값은 true
        },
        {
          "name": "Preview",
          "source": "Origin",   // 원본이 아니라 다른 단계에서 생성된 결과물을 쓰려면 다른 단계의 지시자를 사용
          "size": [
            600,600
          ],
          "path_head": "/site01/Preview",
          "force_dir": true,
          "overwrite": true,
          "comp": 80
        }
      ]
    },
    ......
    "템플릿명2": {
      "description": "이미지, 동영상을 한꺼번에 변환하는 예제 템플릿",
      "proc": [
        {
          "name": "Origin",         // 이미지용 오리진 생성
          "source": "hires",
          "source_ext": "jpg",      // 소스 확장자로 구분하는 필터. png도 포함하는 이미지를 모두 변환하고 싶으면 !mp4라고 하면 됨
          "strip": true,
          "cmyk": true,
          "srgb": true,
          "size": [
            1000,1000
          ],
          "path_head": "/site02/Origin",
          "save": false
        },
        {
          "name": "Origin",         // 동영상용 오리진 이미지파일 생성
          "source": "hires",
          "source_ext": "mp4",
          "path_head": "/site02/Origin",
          "size": [
            1000,1000
          ],
          "save": false
        },
        {
          "name": "Preview",        // 이미지용 프리뷰
          "source": "Origin",
          "source_ext": "jpg",
          "size": [600,600],
          "path_head": "/site02/Preview",     // 이미지 프리뷰용 디렉토리
          "force_dir": true,
          "save": true,
          "watermark": true,
          "overwrite": true
        },
        {
          "name": "Preview",        // 동영상용 프리뷰
          "source": "hires",        // 동영상 Origin은 썸네일 저장하기 위해 만든거임. 동영상은 hires에서 불러와야 함
          "source_ext": "mp4",
          "size": [640,640],
          "path_head": "/site02/mp4",         // 동영상용 프리뷰 디렉토리
          "force_dir": true,
          "save": true,
          "watermark": true,
          "bitrate": 300,          // 동영상용 최대 비트레이트 지정하려면 이 값을 설정함
          "overwrite": true
        },
        ... 나머지는 동일. 썸네일용 원본은 모두 Origin을 쓰면 됨
      ]
    }
}
```

## API
기능 | 구분 | 값 | 설명
------------ | ------------ | ------------ | ------------
작업 요청 | URL	| /api/?act=convert&json=[JSON]	| 작업을 요청함. 
&nbsp; |request|{<br>  "act":"convert"<br>  "json": [작업파일내용(JSON형식)],<br>  "fileupload":[text 파일명]<br>}<br>| GET보다 POST를 권장 json['filelist'] : true이면 include가 무시되며, FILE 업로드가 추가되어야 함.<br>이 경우 form-data로 전송할것 FILE은 파일경로 포함한 파일이름이 1라인당 1개씩 들어가야 함.<br>라인은 \n로 구분되어야 함
&nbsp; |response|{<br>  "result":"sucess",<br>  "seq": "2112120011223" <br>}|daemon: 데몬 상태값. 실행중이면 running, 아니면 stopped<br><br>result :<br><br>success = 성공<br>duplicated = 대기중이거나 진행중인 작업과 동일한 JSON을 보내면 중복을 허용하지 않고 기존의 seq를 돌려줌. 성공이긴 함<br>invalid json key = 작업 파일내의 필수 값이 없음<br>invalid site value = config.json에 지정된 site를 사용하지 않음<br>file save failed = 파일 저장 오류<br>invalid json format = request의 json이 JSON 타입이 아님<br>seq : 유니크한 값으로 다른 요청을 할때 사용하는 키값
작업 상태|URL|	/api/?act=status&seq=[시퀀스값]|	convert에서 받은 seq로 상태 확인
&nbsp; |request	|{<br>  "act":"status"<br>  "seq": [시퀀스값]<br>}|
&nbsp; |response|{<br>  "result" : "상태",<br>  "total" : 0,<br>  "processed" : 0,<br>  "finished" : 0<br>}|result :<br><br>waiting = 대기중임<br>starting = 시작되었음<br>finished = 종료됨. 이 경우에만 다음의 값들이 의미있음<br>	total : 총 파일 갯수<br>	processed : 변환 요청된 갯수<br>	finished : 변환 완료된 갯수<br>error = 오류 발생하여 중단됨. 이 경우에만 다음의 값들이 의미있음<br>	result : 오류 내용 (source not found = 파일 변환일 경우 소스 파일이 없음. 상세 내용은 errorlist에서 조회)
작업 상세|URL|	/api/?act=seqdetail&seq=[시퀀스값]|요청한 작업의 상세 내역
&nbsp; |request	|{<br>  "act":"seqdetail"<br>  "seq": [시퀀스값]<br>}| 
&nbsp; |response|{<br>    "total": 4072,<br>    "success": 240,<br>    "error": 3832,<br>    "result": "found"<br>}|result<br>file read error = 파일을 읽을수 없음<br>not found = 찾을수 없음<br><br>found = 찾았음. 이 경우에만 다음의 값들이 의미있음<br>total : status의 total과 같은 값이어야 함. 전체 파일 갯수<br>success : 성공한 파일 갯수<br>error : 오류인 파일 갯수
결과 파일 리스트 조회|URL|/api/?act=filelist&seq=[시퀀스값]|해당 시퀀스에서 작업된 파일들의 리스트를 돌려줌
&nbsp; |request	|{<br>  "act":"filelist"<br>  "seq": [시퀀스값],<br>  "start": 0,<br>  "offset": 1000<br>}|파일이 많을 경우 조회 및 리턴이 늦어질수 있으므로 시작 위치 start와 갯수 offset을 지정해야 함.<br><br>지정하지 않을 경우 기본값은 start = 0, offset = 10000.<br><br>offset은 10000 이상 지정해도 10000개로 잘라서 보내줌<br>
&nbsp; |response|{<br>    "list": [<br>        "/0000172.jpg",<br>        "/0000165.jpg",<br>        ......<br>        "/0000417.jpg",<br>        "/0000418.jpg"<br>    ],<br>    "start": 0,<br>    "offset": 1000,<br>    "result": "found"<br>}|result<br><br>file read error = 파일을 읽을수 없음<br>not found = 찾을수 없음<br><br>found = 찾았음. 이 경우에만 다음의 값들이 의미있음<br>list : file 이름의 array 임<br><br>start : 리스트 시작 위치. 정상적인 경우 request의 start와 같음<br><br>offset : 리스트 갯수. 정상적인 경우 request의 offset과 같음. 이 값이 다른 경우는 마지막 부분일 경우임<br>
에러 파일 리스트 조회|URL|/api/?act=errorlist&seq=[시퀀스값]|해당 시퀀스에서 작업된 파일들의 리스트를 돌려줌
&nbsp; |request	|{<br>  "act":"errorlist"<br>  "seq": [시퀀스값],<br>  "start": 0,<br>  "offset": 1000<br>}<br>파일이 많을 경우 조회 및 리턴이 늦어질수 있으므로 시작 위치 start와 갯수 offset을 지정해야 함.<br><br>지정하지 않을 경우 기본값은 start = 0, offset = 1000.<br><br>offset은 10000 이상 지정해도 10000개로 잘라서 보내줌
&nbsp; |response|{<br>    "list": [<br>        "/0000172.jpg",<br>        "/0000165.jpg",<br>        ......<br>        "/0000417.jpg",<br>        "/0000418.jpg"<br>    ],<br>    "start": 0,<br>    "offset": 1000,<br>    "result": "found"<br>}result<br><br>file read error = 파일을 읽을수 없음<br>not found = 찾을수 없음<br><br>found = 찾았음. 이 경우에만 다음의 값들이 의미있음<br>list : file 이름의 array 임<br><br>start : 리스트 시작 위치. 실제 파일의 위치가 아니라 에러 파일만 빼낸 리스트의 위치임. 기본값은 0<br><br>offset : 리스트 갯수. 정상적인 경우 seqdetail에서 나온 error 갯수 이상을 가져올수는 없음. 기본값은 10000
결과 파일 조회|URL|/api/?act=fileresult&seq=[시퀀스값]&val=[파일명]|해당 시퀀스에서 작업된 파일의 결과를 돌려줌
&nbsp; |request	|{<br>  "act":"fileresult"<br>  "seq": [시퀀스값],<br>  "val": [파일명]<br>}
&nbsp; |response|{<br>    "seq": "210111111521025",<br>    "result": "found",<br>    "filename": "/0000165.jpg",<br>    "requested": "8",<br>    "success": "8",<br>    "warning": "0"<br>}|seq : 요청한 seq 값<br><br>result<br><br>file read error = 파일을 읽을수 없음<br>not found = 찾을수 없음<br>found = 찾았음. 이 경우에만 다음의 값들이 의미있음<br>filename : 요청한 파일명<br><br>requested : convert때에 요청된 json에서 proc 에 기재된 파일 생성 단계 수와 일치해야함<br><br>success : 실제 생성된 파일 갯수<br><br>warning : 오류나 경고로 생성되지 않은 파일 갯수

## 사족
* GNU GENERAL PUBLIC LICENSE 입니다. 개인적인 사용은 가능하나, 상업적 이용이나 수정시에는 소스를 공개해야 합니다
* 코드에 대한 문제, 개선, 수정에 대한 참여는 언제나 환영입니다. 의문 사항에 대한 답변은 가능할수도 아닐수도 있습니다
* 특정 기능을 추가하거나 커스터마이징에 대한 무료 요청은 정중히 거절합니다 :)