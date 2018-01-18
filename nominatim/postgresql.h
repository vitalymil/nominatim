/*
*/

#ifndef POSTGRESQL_H
#define POSTGRESQL_H

#define PG_OID_INT8			20
#define PG_OID_INT4			23

#if defined(HAVE_BYTESWAP_H)
#include <byteswap.h>
#elif defined(HAVE_SYS_ENDIAN_H)
#include <sys/endian.h>
#endif

#if __BYTE_ORDER == __BIG_ENDIAN
#define PGint16(x)	(x)
#define PGint32(x)	(x)
#define PGint64(x)	(x)
#else
#define PGint16(x)	__bswap_16 (x)
#define PGint32(x)	__bswap_32 (x)
#define PGint64(x)	__bswap_64 (x)
#endif

const char *build_conninfo(const char *db, const char *username, const char *password, const char *host, const char *port);

#endif
