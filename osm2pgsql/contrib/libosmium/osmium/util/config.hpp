#ifndef OSMIUM_UTIL_CONFIG_HPP
#define OSMIUM_UTIL_CONFIG_HPP

/*

This file is part of Osmium (http://osmcode.org/libosmium).

Copyright 2013-2017 Jochen Topf <jochen@topf.org> and others (see README).

Boost Software License - Version 1.0 - August 17th, 2003

Permission is hereby granted, free of charge, to any person or organization
obtaining a copy of the software and accompanying documentation covered by
this license (the "Software") to use, reproduce, display, distribute,
execute, and transmit the Software, and to prepare derivative works of the
Software, and to permit third-parties to whom the Software is furnished to
do so, all subject to the following:

The copyright notices in the Software and this entire statement, including
the above license grant, this restriction and the following disclaimer,
must be included in all copies of the Software, in whole or in part, and
all derivative works of the Software, unless such copies or derivative
works are solely in the form of machine-executable object code generated by
a source language processor.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE, TITLE AND NON-INFRINGEMENT. IN NO EVENT
SHALL THE COPYRIGHT HOLDERS OR ANYONE DISTRIBUTING THE SOFTWARE BE LIABLE
FOR ANY DAMAGES OR OTHER LIABILITY, WHETHER IN CONTRACT, TORT OR OTHERWISE,
ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
DEALINGS IN THE SOFTWARE.

*/

#include <cstdlib>
#include <cstring>
#include <string>

#ifdef _MSC_VER
# define strcasecmp _stricmp
#endif

namespace osmium {

    namespace config {

        inline int get_pool_threads() noexcept {
            const char* env = getenv("OSMIUM_POOL_THREADS");
            if (env) {
                return std::atoi(env);
            }
            return 0;
        }

        inline bool use_pool_threads_for_pbf_parsing() noexcept {
            const char* env = getenv("OSMIUM_USE_POOL_THREADS_FOR_PBF_PARSING");
            if (env) {
                if (!strcasecmp(env, "off") ||
                    !strcasecmp(env, "false") ||
                    !strcasecmp(env, "no") ||
                    !strcasecmp(env, "0")) {
                    return false;
                }
            }
            return true;
        }

        inline size_t get_max_queue_size(const char* queue_name, size_t default_value) noexcept {
            std::string name{"OSMIUM_MAX_"};
            name += queue_name;
            name += "_QUEUE_SIZE";
            const char* env = getenv(name.c_str());
            if (env) {
                auto value = std::atoi(env);
                return value == 0 ? default_value : value;
            }
            return default_value;
        }

    } // namespace config

} // namespace osmium

#endif // OSMIUM_UTIL_CONFIG_HPP