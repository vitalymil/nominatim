#!/bin/bash

docker build --force-rm --no-cache -f base.dockerfile -t osm/nominatim-base .