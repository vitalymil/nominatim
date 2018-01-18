drop type if exists nearplace cascade;
create type nearplace as (
  place_id BIGINT
);

drop type if exists nearfeature cascade;
create type nearfeature as (
  place_id BIGINT,
  keywords int[],
  rank_address smallint,
  rank_search smallint,
  distance float,
  isguess boolean
);

drop type if exists nearfeaturecentr cascade;
create type nearfeaturecentr as (
  place_id BIGINT,
  keywords int[],
  rank_address smallint,
  rank_search smallint,
  distance float,
  isguess boolean,
  postcode TEXT,
  centroid GEOMETRY
);

drop table IF EXISTS search_name_blank CASCADE;
CREATE TABLE search_name_blank (
  place_id BIGINT,
  search_rank smallint,
  address_rank smallint,
  name_vector integer[],
  centroid GEOMETRY(Geometry, 4326)
  );


CREATE TABLE location_area_country () INHERITS (location_area_large) {ts:address-data};
CREATE INDEX idx_location_area_country_geometry ON location_area_country USING GIST (geometry) {ts:address-index};

CREATE TABLE search_name_country () INHERITS (search_name_blank) {ts:address-data};
CREATE INDEX idx_search_name_country_place_id ON search_name_country USING BTREE (place_id) {ts:address-index};
CREATE INDEX idx_search_name_country_name_vector ON search_name_country USING GIN (name_vector) WITH (fastupdate = off) {ts:address-index};

-- start
CREATE TABLE location_area_large_-partition- () INHERITS (location_area_large) {ts:address-data};
CREATE INDEX idx_location_area_large_-partition-_place_id ON location_area_large_-partition- USING BTREE (place_id) {ts:address-index};
CREATE INDEX idx_location_area_large_-partition-_geometry ON location_area_large_-partition- USING GIST (geometry) {ts:address-index};

CREATE TABLE search_name_-partition- () INHERITS (search_name_blank) {ts:address-data};
CREATE INDEX idx_search_name_-partition-_place_id ON search_name_-partition- USING BTREE (place_id) {ts:address-index};
CREATE INDEX idx_search_name_-partition-_centroid ON search_name_-partition- USING GIST (centroid) {ts:address-index};
CREATE INDEX idx_search_name_-partition-_name_vector ON search_name_-partition- USING GIN (name_vector) WITH (fastupdate = off) {ts:address-index};

CREATE TABLE location_road_-partition- (
  place_id BIGINT,
  partition SMALLINT,
  country_code VARCHAR(2),
  geometry GEOMETRY(Geometry, 4326)
  ) {ts:address-data};
CREATE INDEX idx_location_road_-partition-_geometry ON location_road_-partition- USING GIST (geometry) {ts:address-index};
CREATE INDEX idx_location_road_-partition-_place_id ON location_road_-partition- USING BTREE (place_id) {ts:address-index};

-- end
