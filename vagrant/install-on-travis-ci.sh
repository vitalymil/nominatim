#!/bin/bash

# This script runs in a travis-ci.org virtual machine
# https://docs.travis-ci.com/user/trusty-ci-environment/
# Ubuntu 14 (trusty)
# user 'travis'
# $TRAVIS_BUILD_DIR is /home/travis/build/openstreetmap/Nominatim/, for others see
#   https://docs.travis-ci.com/user/environment-variables/#Default-Environment-Variables
# Postgres 9.6 installed and started. role 'travis' already superuser
# Python 3.6
# Travis has a 4 MB, 10000 line output limit, so where possible we run script --quiet


sudo apt-get update -qq
sudo apt-get install -y -qq libboost-dev libboost-system-dev \
                            libboost-filesystem-dev libexpat1-dev zlib1g-dev libxml2-dev\
                            libbz2-dev libpq-dev libgeos-c1 libgeos++-dev libproj-dev \
                            postgresql-server-dev-9.6 postgresql-9.6-postgis-2.3 postgresql-contrib-9.6 \
                            apache2 php5 php5-pgsql php-pear php-db php5-intl

sudo apt-get install -y -qq python3-dev python3-pip python3-psycopg2 phpunit php5-cgi

pip3 install --quiet behave nose pytidylib psycopg2
sudo pear -q install PHP_CodeSniffer

sudo -u postgres createuser -S www-data

# Make sure that system servers can read from the home directory:
chmod a+x $HOME
chmod a+x $TRAVIS_BUILD_DIR


sudo tee /etc/apache2/conf-available/nominatim.conf << EOFAPACHECONF > /dev/null
    <Directory "$TRAVIS_BUILD_DIR/build/website">
      Options FollowSymLinks MultiViews
      AddType text/html   .php
      DirectoryIndex search.php
      Require all granted
    </Directory>

    Alias /nominatim $TRAVIS_BUILD_DIR/build/website
EOFAPACHECONF


sudo a2enconf nominatim
sudo service apache2 restart

wget -O $TRAVIS_BUILD_DIR/data/country_osm_grid.sql.gz http://www.nominatim.org/data/country_grid.sql.gz

mkdir build
cd build
cmake $TRAVIS_BUILD_DIR
make


tee settings/local.php << EOF
<?php
 @define('CONST_Website_BaseURL', '/nominatim/');
 @define('CONST_Database_DSN', 'pgsql://@/test_api_nominatim');
 @define('CONST_Wikipedia_Data_Path', CONST_BasePath.'/test/testdb');
EOF

