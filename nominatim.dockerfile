FROM osm/nominatim-base

COPY . /nominatim
RUN mkdir /nominatim-build
WORKDIR /nominatim-build
RUN cmake /nominatim
RUN make

COPY nominatim.conf /etc/apache2/conf-available/
RUN sed -i 's:#.*::' /etc/apache2/conf-available/nominatim.conf
RUN a2enconf nominatim

WORKDIR /
COPY osmosis-latest.tgz .
RUN mkdir osmosis
RUN mv osmosis-latest.tgz osmosis
WORKDIR /osmosis
RUN tar xvfz osmosis-latest.tgz
RUN rm osmosis-latest.tgz
WORKDIR /

RUN update-alternatives --install /usr/bin/python python /usr/bin/python3.5 1

CMD service apache2 start && tail -f /var/log/apache2/access.log