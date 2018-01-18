/*
 * Dirty tile list generation
 *
 * Steve Hill <steve@nexusuk.org>
 *
 * Please refer to the OpenPisteMap expire_tiles.py script for a demonstration
 * of how to make use of the output:
 * http://subversion.nexusuk.org/projects/openpistemap/trunk/scripts/expire_tiles.py
 */

#include <cmath>
#include <cstdlib>
#include <cstring>
#include <cerrno>
#include <string>

#include "expire-tiles.hpp"
#include "options.hpp"
#include "reprojection.hpp"
#include "table.hpp"
#include "wkb.hpp"

#define EARTH_CIRCUMFERENCE		40075016.68
#define HALF_EARTH_CIRCUMFERENCE	(EARTH_CIRCUMFERENCE / 2)
#define TILE_EXPIRY_LEEWAY		0.1		/* How many tiles worth of space to leave either side of a changed feature */

tile_output_t::tile_output_t(const char *filename)
: outfile(fopen(filename, "a"))
{
    if (outfile == nullptr) {
        fprintf(stderr, "Failed to open expired tiles file (%s).  Tile expiry "
                        "list will not be written!\n",
                strerror(errno));
    }
}

tile_output_t::~tile_output_t()
{
    if (outfile) {
        fclose(outfile);
    }
}

void tile_output_t::output_dirty_tile(uint32_t x, uint32_t y, uint32_t zoom)
{
    if (outfile) {
        fprintf(outfile, "%i/%i/%i\n", zoom, x, y);
        ++outcount;
        if (outcount % 1000 == 0) {
            fprintf(stderr, "\rWriting dirty tile list (%iK)", outcount / 1000);
        }
    }
}

void expire_tiles::output_and_destroy(const char *filename, uint32_t minzoom)
{
    tile_output_t output_writer(filename);
    output_and_destroy<tile_output_t>(output_writer, minzoom);
}

expire_tiles::expire_tiles(uint32_t max, double bbox,
                           const std::shared_ptr<reprojection> &proj)
: max_bbox(bbox), maxzoom(max), projection(proj)
{
    map_width = 1 << maxzoom;
    tile_width = EARTH_CIRCUMFERENCE / map_width;
    last_tile_x = static_cast<uint32_t>(map_width) + 1;
    last_tile_y = static_cast<uint32_t>(map_width) + 1;
}

uint64_t expire_tiles::xy_to_quadkey(uint32_t x, uint32_t y, uint32_t zoom)
{
    uint64_t quadkey = 0;
    // the two highest bits are the bits of zoom level 1, the third and fourth bit are level 2, …
    for (uint32_t z = 0; z < zoom; z++) {
        quadkey |= ((x & (1ULL << z)) << z);
        quadkey |= ((y & (1ULL << z)) << (z + 1));
    }
    return quadkey;
}

xy_coord_t expire_tiles::quadkey_to_xy(uint64_t quadkey_coord, uint32_t zoom)
{
    xy_coord_t result;
    for (uint32_t z = zoom; z > 0; --z) {
        /* The quadkey contains Y and X bits interleaved in following order: YXYX...
         * We have to pick out the bit representing the y/x bit of the current zoom
         * level and then shift it back to the right on its position in a y-/x-only
         * coordinate.*/
        result.y = result.y + static_cast<uint32_t>(
                                  (quadkey_coord & (1ULL << (2 * z - 1))) >> z);
        result.x = result.x +
                   static_cast<uint32_t>(
                       (quadkey_coord & (1ULL << (2 * (z - 1)))) >> (z - 1));
    }
    return result;
}

void expire_tiles::expire_tile(uint32_t x, uint32_t y)
{
    // Only try to insert to tile into the set if the last inserted tile
    // is different from this tile.
    if (last_tile_x != x || last_tile_y != y) {
        m_dirty_tiles.insert(xy_to_quadkey(x, y, maxzoom));
        last_tile_x = x;
        last_tile_y = y;
    }
}

int expire_tiles::normalise_tile_x_coord(int x) {
	x %= map_width;
	if (x < 0) x = (map_width - x) + 1;
	return x;
}

/*
 * Expire tiles that a line crosses
 */
void expire_tiles::from_line(double lon_a, double lat_a, double lon_b, double lat_b) {
	double	tile_x_a;
	double	tile_y_a;
	double	tile_x_b;
	double	tile_y_b;
	double	temp;
	double	x1;
	double	y1;
	double	x2;
	double	y2;
	double	hyp_len;
	double	x_len;
	double	y_len;
	double	x_step;
	double	y_step;
	double	step;
	double	next_step;
	int	x;
	int	y;
	int	norm_x;

    projection->coords_to_tile(&tile_x_a, &tile_y_a, lon_a, lat_a, map_width);
    projection->coords_to_tile(&tile_x_b, &tile_y_b, lon_b, lat_b, map_width);

	if (tile_x_a > tile_x_b) {
		/* We always want the line to go from left to right - swap the ends if it doesn't */
		temp = tile_x_b;
		tile_x_b = tile_x_a;
		tile_x_a = temp;
		temp = tile_y_b;
		tile_y_b = tile_y_a;
		tile_y_a = temp;
	}

	x_len = tile_x_b - tile_x_a;
	if (x_len > map_width / 2) {
		/* If the line is wider than half the map, assume it
           crosses the international date line.
           These coordinates get normalised again later */
		tile_x_a += map_width;
		temp = tile_x_b;
		tile_x_b = tile_x_a;
		tile_x_a = temp;
		temp = tile_y_b;
		tile_y_b = tile_y_a;
		tile_y_a = temp;
	}
	y_len = tile_y_b - tile_y_a;
	hyp_len = sqrt(pow(x_len, 2) + pow(y_len, 2));	/* Pythagoras */
	x_step = x_len / hyp_len;
	y_step = y_len / hyp_len;

	for (step = 0; step <= hyp_len; step+= 0.4) {
		/* Interpolate points 1 tile width apart */
		next_step = step + 0.4;
		if (next_step > hyp_len) next_step = hyp_len;
		x1 = tile_x_a + ((double)step * x_step);
		y1 = tile_y_a + ((double)step * y_step);
		x2 = tile_x_a + ((double)next_step * x_step);
		y2 = tile_y_a + ((double)next_step * y_step);

		/* The line (x1,y1),(x2,y2) is up to 1 tile width long
           x1 will always be <= x2
           We could be smart and figure out the exact tiles intersected,
           but for simplicity, treat the coordinates as a bounding box
           and expire everything within that box. */
		if (y1 > y2) {
			temp = y2;
			y2 = y1;
			y1 = temp;
		}
		for (x = x1 - TILE_EXPIRY_LEEWAY; x <= x2 + TILE_EXPIRY_LEEWAY; x ++) {
			norm_x =  normalise_tile_x_coord(x);
			for (y = y1 - TILE_EXPIRY_LEEWAY; y <= y2 + TILE_EXPIRY_LEEWAY; y ++) {
				expire_tile(norm_x, y);
			}
		}
	}
}

/*
 * Expire tiles within a bounding box
 */
int expire_tiles::from_bbox(double min_lon, double min_lat, double max_lon, double max_lat) {
	double		width;
	double		height;
	int		min_tile_x;
	int		min_tile_y;
	int		max_tile_x;
	int		max_tile_y;
	int		iterator_x;
	int		iterator_y;
	int		norm_x;
	int		ret;
    double  tmp_x;
    double  tmp_y;

	if (maxzoom == 0) return 0;

	width = max_lon - min_lon;
	height = max_lat - min_lat;
	if (width > HALF_EARTH_CIRCUMFERENCE + 1) {
		/* Over half the planet's width within the bounding box - assume the
           box crosses the international date line and split it into two boxes */
		ret = from_bbox(-HALF_EARTH_CIRCUMFERENCE, min_lat, min_lon, max_lat);
		ret += from_bbox(max_lon, min_lat, HALF_EARTH_CIRCUMFERENCE, max_lat);
		return ret;
	}

    if (width > max_bbox || height > max_bbox) {
        return -1;
    }


	/* Convert the box's Mercator coordinates into tile coordinates */
        projection->coords_to_tile(&tmp_x, &tmp_y, min_lon, max_lat, map_width);
        min_tile_x = tmp_x - TILE_EXPIRY_LEEWAY;
        min_tile_y = tmp_y - TILE_EXPIRY_LEEWAY;
        projection->coords_to_tile(&tmp_x, &tmp_y, max_lon, min_lat, map_width);
        max_tile_x = tmp_x + TILE_EXPIRY_LEEWAY;
        max_tile_y = tmp_y + TILE_EXPIRY_LEEWAY;
	if (min_tile_x < 0) min_tile_x = 0;
	if (min_tile_y < 0) min_tile_y = 0;
	if (max_tile_x > map_width) max_tile_x = map_width;
	if (max_tile_y > map_width) max_tile_y = map_width;
	for (iterator_x = min_tile_x; iterator_x <= max_tile_x; iterator_x ++) {
		norm_x =  normalise_tile_x_coord(iterator_x);
		for (iterator_y = min_tile_y; iterator_y <= max_tile_y; iterator_y ++) {
			expire_tile(norm_x, iterator_y);
		}
	}
	return 0;
}


void expire_tiles::from_wkb(const char *wkb, osmid_t osm_id)
{
    if (maxzoom == 0) {
        return;
    }

    auto parse = ewkb::parser_t(wkb);

    switch (parse.read_header()) {
    case ewkb::wkb_point:
        from_wkb_point(&parse);
        break;
    case ewkb::wkb_line:
        from_wkb_line(&parse);
        break;
    case ewkb::wkb_polygon:
        from_wkb_polygon(&parse, osm_id);
        break;
    case ewkb::wkb_multi_line: {
        auto num = parse.read_length();
        for (unsigned i = 0; i < num; ++i) {
            parse.read_header();
            from_wkb_line(&parse);
        }
        break;
    }
    case ewkb::wkb_multi_polygon: {
        auto num = parse.read_length();
        for (unsigned i = 0; i < num; ++i) {
            parse.read_header();
            from_wkb_polygon(&parse, osm_id);
        }
        break;
    }
    default:
        fprintf(stderr, "OSM id %" PRIdOSMID
                        ": Unknown geometry type, cannot expire.\n",
                osm_id);
    }
}

void expire_tiles::from_wkb_point(ewkb::parser_t *wkb)
{
    auto c = wkb->read_point();
    from_bbox(c.x, c.y, c.x, c.y);
}

void expire_tiles::from_wkb_line(ewkb::parser_t *wkb)
{
    auto sz = wkb->read_length();

    if (sz == 0) {
        return;
    }

    if (sz == 1) {
        from_wkb_point(wkb);
    } else {
        auto prev = wkb->read_point();
        for (size_t i = 1; i < sz; ++i) {
            auto cur = wkb->read_point();
            from_line(prev.x, prev.y, cur.x, cur.y);
            prev = cur;
        }
    }
}

void expire_tiles::from_wkb_polygon(ewkb::parser_t *wkb, osmid_t osm_id)
{
    auto num_rings = wkb->read_length();
    assert(num_rings > 0);

    auto start = wkb->save_pos();

    auto num_pt = wkb->read_length();
    auto initpt = wkb->read_point();

    osmium::geom::Coordinates min{initpt}, max{initpt};

    for (size_t i = 1; i < num_pt; ++i) {
        auto c = wkb->read_point();
        if (c.x < min.x)
            min.x = c.x;
        if (c.y < min.y)
            min.y = c.y;
        if (c.x > max.x)
            max.x = c.x;
        if (c.y > max.y)
            max.y = c.y;
    }

    if (from_bbox(min.x, min.y, max.x, max.y)) {
        /* Bounding box too big - just expire tiles on the line */
        fprintf(stderr,
                "\rLarge polygon (%.0f x %.0f metres, OSM ID %" PRIdOSMID
                ") - only expiring perimeter\n",
                max.x - min.x, max.y - min.y, osm_id);
        wkb->rewind(start);
        for (unsigned ring = 0; ring < num_rings; ++ring) {
            from_wkb_line(wkb);
        }
    } else {
        // ignore inner rings
        for (unsigned ring = 1; ring < num_rings; ++ring) {
            auto inum_pt = wkb->read_length();
            wkb->skip_points(inum_pt);
        }
    }
}

/*
 * Expire tiles based on an osm element.
 * What type of element (node, line, polygon) osm_id refers to depends on
 * sql_conn. Each type of table has its own sql_conn and the prepared statement
 * get_wkb refers to the appropriate table.
 *
 * The function returns -1 if expiry is not enabled. Otherwise it returns the number
 * of elements that refer to the osm_id.

 */
int expire_tiles::from_db(table_t* table, osmid_t osm_id) {
    //bail if we dont care about expiry
    if (maxzoom == 0)
        return -1;

    //grab the geom for this id
    auto wkbs = table->get_wkb_reader(osm_id);

    //dirty the stuff
    const char* wkb = nullptr;
    while ((wkb = wkbs.get_next())) {
        auto binwkb = ewkb::parser_t::wkb_from_hex(wkb);
        from_wkb(binwkb.c_str(), osm_id);
    }

    //return how many rows were affected
    return wkbs.get_count();
}

void expire_tiles::merge_and_destroy(expire_tiles &other)
{
    if (map_width != other.map_width) {
        throw std::runtime_error(
            (boost::format("Unable to merge tile expiry sets when "
                           "map_width does not match: %1% != %2%.") %
             map_width % other.map_width)
                .str());
    }

    if (tile_width != other.tile_width) {
        throw std::runtime_error(
            (boost::format("Unable to merge tile expiry sets when "
                           "tile_width does not match: %1% != %2%.") %
             tile_width % other.tile_width)
                .str());
    }

    if (m_dirty_tiles.size() == 0) {
        m_dirty_tiles = std::move(other.m_dirty_tiles);
    } else {
        m_dirty_tiles.insert(other.m_dirty_tiles.begin(),
                             other.m_dirty_tiles.end());
    }

    other.m_dirty_tiles.clear();
}
