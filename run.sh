#!/bin/bash

docker rm -f osm-nominatim
docker run -p 8080:80 \
           -v $(pwd)/../data:/rep_data \
           --name osm-nominatim \
           --network osm_network \
           -d \
           osm/nominatim