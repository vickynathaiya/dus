#!/bin/bash

if [ $(id -u) != "0" ]; then
    echo "Root Required for script ( $(basename $0) )"
    exit
fi

apt-get update

LOGFILE='/tmp/logfile'
MYSQLPASSWORD='khemraj'

# Install MySQL Server in a Non-Interactive mode. Default root password will be "root"
echo "mysql-server mysql-server/root_password password root" | sudo debconf-set-selections
echo "mysql-server mysql-server/root_password_again password root" | sudo debconf-set-selections
apt-get -y install mysql-server mysql-client >> $LOGFILE 2>&1

echo "-------------Initial DB setup ------------"
MYSQL_VERSION=`dpkg -l mysql-server | grep mysql-server | awk '{print $3}' | awk -F"." '{print $1}'`
echo "MYSQL VERSION = $MYSQL_VERSION"
if [ $MYSQL_VERSION -ge 8 ]
then
    mysql -u root -proot -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQLPASSWORD'"  >> $LOGFILE 2>&1
else
    mysql -u root -proot -e "UPDATE mysql.user SET authentication_string=PASSWORD('$MYSQLPASSWORD') WHERE User='root'"  >> $LOGFILE 2>&1
fi
mysql -u root -proot -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1')"  >> $LOGFILE 2>&1
mysql -u root -proot -e "DELETE FROM mysql.user WHERE User=''"  >> $LOGFILE 2>&1
mysql -u root -proot -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%'"  >> $LOGFILE 2>&1
mysql -u root -proot -e "FLUSH PRIVILEGES"  >> $LOGFILE 2>&1

echo "-------------creating laravel user and crypto db ------------"
mysql -u root -p"$MYSQLPASSWORD" -e "create database crypto"  >> $LOGFILE 2>&1
mysql -u root -p"$MYSQLPASSWORD" -e "create user 'laravel'@'%' identified by 'laravel@dmin'"  >> $LOGFILE 2>&1
mysql -u root -p"$MYSQLPASSWORD" -e "grant all privileges on crypto.* to 'laravel'@'%'"  >> $LOGFILE 2>&1
mysql -u root -p"$MYSQLPASSWORD" -e "FLUSH PRIVILEGES"  >> $LOGFILE 2>&1

echo "------------- restarting mysql server  ------------"
service mysql restart                                                                                                                           
   
echo "------------- installing composer --------------------"
apt-get -y install curl php7.4-cli php7.4-mbstring git unzip

curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php --install-dir=/usr/local/bin --filename=composer

echo "------------- create laravel user --------------------"
username=laravel
password=laravel@dmin
groupadd laravel
useradd -g laravel -s /bin/bash -m -c "laravel user" laravel
chpasswd <<<"$username:$password"

echo "------------- installing essentials php modules --------------------"
apt -y install  php7.4-xml  php7.4-dom php7.4-gmp php7.4-mysql

echo "------------- create laravel project --------------------"
sudo -u $username -H sh -c "cd ~; composer create-project laravel/laravel crypto"

 
echo "------------- add arkecosystem package --------------------"
sudo -u $username -H sh -c "cd ~/crypto; composer require arkecosystem/crypto"

echo "------------- add schedtransactions package --------------------"
sudo -u $username -H sh -c "cd ~/crypto; composer require vickynathaiya/dus"

echo "------------- updating .env  --------------------"
cd ~laravel/crypto
sed -i -e 's/DB_DATABASE.*/DB_DATABASE=crypto/g' .env
sed -i -e 's/DB_USERNAME.*/DB_USERNAME=laravel/g' .env
sed -i -e 's/DB_PASSWORD.*/DB_PASSWORD=laravel@dmin/g' .env


echo "------------- update system crontab --------------------"
sudo -u $username -H sh -c "cd ~/crypto; php artisan crypto:cron add_cron"


echo "------------- artisan migrate --------------------"
sudo -u $username -H sh -c "cd ~/crypto; php artisan migrate"


echo "------------- restarting cron service --------------------"
service cron restart