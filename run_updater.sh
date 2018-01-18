#!/bin/bash

docker rm -f osm-nominatim-updater
docker run -e PGHOST=osm-dev-db -e PGUSER=postgres -e PGPASSWORD=postgres \
           -v $(pwd)/../data:/rep_data \
           --name osm-nominatim-updater \
           --network osm_network \
           -d \
           osm/nominatim \
           /nominatim-build/utils/update.php --import-osmosis-all