#! /bin/sh

if [ "$EUID" -ne 0 ]
  then echo "Please run as root"
  exit
fi

mkdir -p /opt/tmc/config /opt/tmc/done /opt/tmc/err /opt/tmc/log /opt/tmc/proc /opt/tmc/req /opt/tmc/api/data /opt/tmc/temp
cp -f ./tmc*.php  /opt/tmc/
cp -f ./config/*.png ./config/template.json ./config/*.icc /opt/tmc/config/
cp -n ./config/config.json  /opt/tmc/config/
cp -f -R ./vendor /opt/tmc/
cp tmc /etc/init.d/
chmod +x /etc/init.d/tmc

cp -f ./api/* /opt/tmc/api/
cp -f -R ./api/js /opt/tmc/api/
cp -f ./api/.htaccess /opt/tmc/api/
chown apache.apache /opt/tmc/api /opt/tmc/req /opt/tmc/proc /opt/tmc/err
echo "Finished"