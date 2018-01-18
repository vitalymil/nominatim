#ifndef GEOMETRY_PROCESSOR_HPP
#define GEOMETRY_PROCESSOR_HPP

#include <cstddef>
#include <string>
#include <vector>
#include <memory>

#include <osmium/memory/buffer.hpp>

#include "osmium-builder.hpp"
#include "osmtypes.hpp"
#include "tagtransform.hpp"

struct middle_query_t;
struct middle_t;
struct options_t;
class reprojection;

struct geometry_processor {
    using wkb_t = geom::osmium_builder_t::wkb_t;
    using wkbs_t = geom::osmium_builder_t::wkbs_t;
    // factory method for creating various types of geometry processors either by name or by geometry column type
    static std::shared_ptr<geometry_processor> create(const std::string &type,
                                                        const options_t *options);

    virtual ~geometry_processor();

    enum interest {
        interest_NONE     = 0,
        interest_node     = 1,
        interest_way      = 2,
        interest_relation = 4,
        interest_ALL      = 7
    };

    // return bit-mask of the type of elements this processor is
    // interested in.
    unsigned int interests() const;

    // return true if provided intrest is an interest of this processor
    bool interests(unsigned int interested) const;

    // the postgis column type for the kind of geometry (i.e: POINT,
    // LINESTRING, etc...) that this processor outputs
    const std::string &column_type() const;

    // process a node, optionally returning a WKB string describing
    // geometry to be inserted into the table.
    virtual wkb_t process_node(osmium::Location const &loc,
                               geom::osmium_builder_t *builder);

    // process a way
    // position data and optionally returning WKB-encoded geometry
    // for insertion into the table.
    virtual wkb_t process_way(osmium::Way const &way,
                              geom::osmium_builder_t *builder);

    // process a way, taking a middle query object to get way and
    // node position data. optionally returns an array of WKB-encoded geometry
    // for insertion into the table.
    virtual wkbs_t process_relation(osmium::Relation const &rel,
                                    osmium::memory::Buffer const &ways,
                                    geom::osmium_builder_t *builder);

    // returns the SRID of the output geometry.
    int srid() const;

protected:
    // SRID of the geometry output
    const int m_srid;

    // WKT type of the geometry output
    const std::string m_type;

    // mask of elements that this processor is interested in
    const unsigned int m_interests;

    // constructor for use by implementing classes only
    geometry_processor(int srid, const std::string &type, unsigned int interests);
};

//various bits for continuous processing of members of relations
class relation_helper
{
public:
    relation_helper();

    size_t set(osmium::Relation const &rel, middle_t const *mid);
    multitaglist_t get_filtered_tags(tagtransform_t *transform,
                                     export_list const &el) const;
    void add_way_locations(middle_t const *mid);

    rolelist_t roles;
    std::vector<int> superseded;
    osmium::memory::Buffer data;
};

#endif /* GEOMETRY_PROCESSOR_HPP */
