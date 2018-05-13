FROM ubuntu:16.04
COPY apt.conf /etc/apt/
ENV https_proxy=http://user108:User108!@172.16.0.254:8080
ENV http_proxy=http://user108:User108!@172.16.0.254:8080

RUN apt-get update -q
RUN apt-get -o DPkg::options::="--force-confdef" -o DPkg::options::="--force-confold" --force-yes -fuy install grub-pc
RUN apt-get update -q

RUN apt-get install -y build-essential
RUN apt-get install -y g++
RUN apt-get install -y libboost-dev
RUN apt-get install -y libboost-system-dev
RUN apt-get install -y libboost-filesystem-dev
RUN apt-get install -y libexpat1-dev
RUN apt-get install -y zlib1g-dev
RUN apt-get install -y libxml2-dev
RUN apt-get install -y libbz2-dev libpq-dev libgeos-dev libgeos++-dev libproj-dev
RUN apt-get install -y postgresql-server-dev-9.5 postgresql-9.5-postgis-2.2 postgresql-contrib-9.5
RUN apt-get install -y apache2 php php-pgsql libapache2-mod-php php-pear php-db
RUN apt-get install -y php-intl git
RUN apt-get install -y cmake
RUN apt-get install -y python3-setuptools python3-dev python3-pip \
                        python3-psycopg2 python3-tidylib phpunit php-cgi

RUN apt-get install -y libboost-python-dev

RUN pip3 install --user behave nose osmium
RUN pear install PHP_CodeSniffer
ENV https_proxy=
ENV http_proxy=
