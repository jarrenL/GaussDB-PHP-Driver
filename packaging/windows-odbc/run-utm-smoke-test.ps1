$ErrorActionPreference = 'Stop'

$parameters = @{
    ConnectionString = 'Driver={GaussDB Unicode};Servername=192.168.64.1;Port=15432;Database=gdbdrv_m_test;SSLmode=prefer'
}

& (Join-Path $PSScriptRoot 'run-smoke-test.ps1') @parameters
exit $LASTEXITCODE
