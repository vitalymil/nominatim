#include "id-tracker.hpp"

#include <map>
#include <vector>
#include <limits>
#include <algorithm>

#include <boost/optional.hpp>

#define BLOCK_BITS (16)
#define BLOCK_SIZE (1 << BLOCK_BITS)
#define BLOCK_MASK (BLOCK_SIZE - 1)

namespace {
/* block used to be just a std::vector<bool> of fixed size. however,
 * it seems there's significant overhead in exposing std::vector<bool>::iterator
 * and so this is now a minimal re-implementation.
 *
 * each block is BLOCK_SIZE bits, stored as a vector of uint32_t elements.
 */
struct block {
    block() : bits(BLOCK_SIZE >> 5, 0) {}
    inline bool operator[](size_t i) const { return (bits[i >> 5] & (1 << (i & 0x1f))) > 0; }
    //returns true if the value actually caused a bit to flip
    inline bool set(size_t i, bool value) {
        uint32_t &bit = bits[i >> 5];
        uint32_t old = bit;
        uint32_t mask = 1 << (i & 0x1f);
        //allow the bit to become 1 if not already
        if (value) {
            bit |= mask;
        }//force the bit to 0 if its not already
        else {
            bit &= ~mask;
        }
        //did it actually change the value
        return old != bit;
    }
    // find the next bit which is set, starting from an initial offset
    // of start. this offset is a bit like an iterator, but not fully
    // supporting iterator movement forwards and backwards.
    //
    // returns BLOCK_SIZE if a set bit isn't found
    size_t next_set(size_t start) const {
        uint32_t bit_i = start >> 5;

        while ((bit_i < (BLOCK_SIZE >> 5)) && (bits[bit_i] == 0)) {
            ++bit_i;
        }

        if (bit_i >= (BLOCK_SIZE >> 5)) { return BLOCK_SIZE; }
        uint32_t bit = bits[bit_i];
        size_t idx = bit_i << 5;
        while ((bit & 1) == 0) { ++idx; bit >>= 1; }
        return idx;
    }
private:
    std::vector<uint32_t> bits;
};
} // anonymous namespace

struct id_tracker::pimpl {
    pimpl();
    ~pimpl();

    bool get(osmid_t id) const;
    bool set(osmid_t id, bool value);
    osmid_t pop_min();

    typedef std::map<osmid_t, block> map_t;
    map_t pending;
    osmid_t old_id;
    size_t count;
    // a cache of the next starting point to search for in the block.
    // this significantly speeds up pop_min() because it doesn't need
    // to repeatedly search the beginning of the block each time.
    boost::optional<size_t> next_start;
};

bool id_tracker::pimpl::get(osmid_t id) const {
    const osmid_t block = id >> BLOCK_BITS, offset = id & BLOCK_MASK;
    map_t::const_iterator itr = pending.find(block);
    bool result = false;

    if (itr != pending.end()) {
        result = itr->second[offset];
    }

    return result;
}

bool id_tracker::pimpl::set(osmid_t id, bool value) {
    const osmid_t block = id >> BLOCK_BITS, offset = id & BLOCK_MASK;
    bool flipped = pending[block].set(offset, value);
    // a set may potentially invalidate a next_start, as the bit
    // set might be before the position of next_start.
    if (next_start) { next_start = boost::none; }
    return flipped;
}

// find the first element in a block set to true
osmid_t id_tracker::pimpl::pop_min() {
    osmid_t id = max();

    while (next_start || !pending.empty()) {
        map_t::iterator itr = pending.begin();
        block &b = itr->second;
        size_t start = next_start.get_value_or(0);

        size_t b_itr = b.next_set(start);
        if (b_itr != BLOCK_SIZE) {
            b.set(b_itr, false);
            id = (itr->first << BLOCK_BITS) | b_itr;
            next_start = b_itr;
            break;

        } else {
            // no elements in this block - might as well delete
            // the whole thing.
            pending.erase(itr);
            // since next_start is relative to the current
            // block, which is ceasing to exist, then we need to
            // reset it.
            next_start = boost::none;
        }
    }

    return id;
}

id_tracker::pimpl::pimpl()
    : pending(), old_id(min()), count(0), next_start(boost::none) {
}

id_tracker::pimpl::~pimpl() {
}

id_tracker::id_tracker(): impl() {
    impl.reset(new pimpl());
}

id_tracker::~id_tracker() {
}

void id_tracker::mark(osmid_t id) {
    //setting returns true if the id wasn't already marked
    impl->count += size_t(impl->set(id, true));
    //we've marked something so we need to be able to pop it
    //the assert below will fail though if we've already popped
    //some that were > id so we have to essentially reset to
    //allow for more pops to take place
    impl->old_id = min();
}

bool id_tracker::is_marked(osmid_t id) {
    return impl->get(id);
}

osmid_t id_tracker::pop_mark() {
    osmid_t id = impl->pop_min();

    assert((id > impl->old_id) || !id_tracker::is_valid(id));
    impl->old_id = id;

    //we just go rid of one (if there were some to get rid of)
    if(impl->count > 0)
        impl->count--;

    return id;
}

size_t id_tracker::size() const { return impl->count; }

osmid_t id_tracker::last_returned() const { return impl->old_id; }

bool id_tracker::is_valid(osmid_t id) { return id != max(); }
osmid_t id_tracker::max() { return std::numeric_limits<osmid_t>::max(); }
osmid_t id_tracker::min() { return std::numeric_limits<osmid_t>::min(); }
