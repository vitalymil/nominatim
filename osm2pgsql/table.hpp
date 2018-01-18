#ifndef TABLE_H
#define TABLE_H

#include "pgsql.hpp"
#include "osmtypes.hpp"
#include "taginfo.hpp"

#include <cstddef>
#include <string>
#include <vector>
#include <utility>
#include <memory>

#include <boost/optional.hpp>
#include <boost/format.hpp>

typedef std::vector<std::string> hstores_t;

class table_t
{
    public:
        table_t(const std::string& conninfo, const std::string& name, const std::string& type, const columns_t& columns, const hstores_t& hstore_columns, const int srid,
                const bool append, const bool slim, const bool droptemp, const int hstore_mode, const bool enable_hstore_index,
                const boost::optional<std::string>& table_space, const boost::optional<std::string>& table_space_index);
        table_t(const table_t& other);
        ~table_t();

        void start();
        void stop();

        void begin();
        void commit();

        void write_row(osmid_t id, taglist_t const &tags, std::string const &geom);
        void delete_row(const osmid_t id);

        std::string const& get_name();

        //interface from retrieving well known binary geometry from the table
        class wkb_reader
        {
            friend table_t;
            public:
                const char* get_next()
                {
                    if (m_current < m_count) {
                        return PQgetvalue(m_result.get(), m_current++, 0);
                    }
                    return nullptr;
                }

                int get_count() const { return m_count; }
                void reset()
                {
                    //NOTE: PQgetvalue doc doesn't say if you can call it
                    //      multiple times with the same row col
                    m_current = 0;
                }
            private:
                wkb_reader(pg_result_t &&result)
                : m_result(std::move(result)), m_count(PQntuples(result.get())),
                  m_current(0)
                {}

                pg_result_t m_result;
                int m_count;
                int m_current;
        };
        wkb_reader get_wkb_reader(const osmid_t id);

    protected:
        void connect();
        void stop_copy();
        void teardown();

        void write_columns(const taglist_t &tags, std::string& values, std::vector<bool> *used);
        void write_tags_column(const taglist_t &tags, std::string& values,
                               const std::vector<bool> &used);
        void write_hstore_columns(const taglist_t &tags, std::string& values);

        void escape4hstore(const char *src, std::string& dst);
        void escape_type(const std::string &value, ColumnType flags, std::string& dst);

        std::string conninfo;
        std::string name;
        std::string type;
        pg_conn *sql_conn;
        bool copyMode;
        std::string buffer;
        std::string srid;
        bool append;
        bool slim;
        bool drop_temp;
        int hstore_mode;
        bool enable_hstore_index;
        columns_t columns;
        hstores_t hstore_columns;
        std::string copystr;
        boost::optional<std::string> table_space;
        boost::optional<std::string> table_space_index;

        boost::format single_fmt, del_fmt;
};

#endif
