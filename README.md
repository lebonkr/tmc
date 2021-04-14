# tmc
PHP Thumbnail Multi-process Converter

1. 개요
- PHP상에서 Multi-Process로 동시에 여러개의 이미지의 변환 : gearman 사용
- 이미지 변환 기능은 php-pecl-imagick 사용 : Resize, Sharpen, Compression, Profile, Watermark등
- mp4 동영상에서 프레임 추출, 추출된 이미지 변환 가능
- REST API 지원, 작업 요청/제어/상태를 API로 함

2. 요구사항
(필수)
- OS : CentOS 7 이상
- PHP ≥ 5.4.x
- PHP Library : php-pecl-imagick, php-pecl-gearman
- gearman ≥ 1.1.12
(옵션)
- ffmpeg ≥ 3.4.8
- REST API 사용하려면 Apache 또는 nginx 필요

3. 설치 가이드 (CentOS 7.9기준)
- Apache 또는 nginx DocumentRoot = /opt/tmc/api

- install php 
yum install php php-pecl-imagick php-pecl-gearman 

- install gearmand 
yum install gearmand
service gearmand start

- install ffmpeg
  * install composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '756890a4488ce9024fc62c56153228907f1545c228516cbf63f885e036d37e9a59d27d63f46af1d4d07ee0f76181c7d3') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
cp composer.phar /usr/local/bin/
ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

  * install php-ffmpeg 
composer require php-ffmpeg/php-ffmpeg

  * install ffmpeg
yum install epel-release
yum localinstall --nogpgcheck https://download1.rpmfusion.org/free/el/rpmfusion-free-release-7.noarch.rpm
yum install ffmpeg ffmpeg-devel

4. TMC 설치
- install directory : /opt/tmc
- git clone 후
./install.sh

5. TMC 제어
- 데몬 실행/재시작
# 실행
service tmc start
# 재시작
service tmc restart

- 데몬 상태
service tmc status
# 응답
TMC status:running [2021-03-16 14:34:42]
(working/running) workers : (0/20)
Total processed : 98

- 데몬 종료 : 실행중이던 모든 프로세스는 정지되고 나서 종료됨. (별다른 조치가 없이) 재개하면 정지되었던 프로세스는 다시 시작됨.
service tmc stop

- 데몬 정보
pid 파일 : /var/run/tmc.pid
실행 로그 : /var/log/tmc.log
client 로그 : /opt/tmc/log/client.log
worker 로그 : /opt/tmc/log/worker.log

6. 사용방법
