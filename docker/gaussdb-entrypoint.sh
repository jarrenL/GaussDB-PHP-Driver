#!/bin/bash
set -euo pipefail

export GAUSSHOME=/opt/gaussdb/app
export LD_LIBRARY_PATH=/opt/gaussdb/app/lib
export PATH=/opt/gaussdb/app/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

exec /opt/gaussdb/app/bin/gaussdb --single_node -D /opt/gaussdb/data
