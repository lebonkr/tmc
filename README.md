# TMC (Thumbnail Multi-process Converter)

## 개요
* PHP상에서 Multi-Process로 동시에 여러개의 이미지의 변환 : gearman 사용
* 이미지 변환 기능은 php-pecl-imagick 사용 : Resize, Sharpen, Compression, Profile, Watermark등
* mp4 동영상에서 프레임 추출, 추출된 이미지 변환 가능
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
* 데몬 실행/재시작
  * 실행
```
service tmc start
```
  * 재시작
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
1. 