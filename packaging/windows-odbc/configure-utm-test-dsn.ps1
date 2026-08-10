$ErrorActionPreference = 'Stop'
try {
    $parameters = @{
        Name = 'GaussDB507'
        DriverName = 'GaussDB Unicode'
        Server = '192.168.64.1'
        Port = 15432
        Database = 'gdbdrv_m_test'
        Platform = '64-bit'
    }

    & (Join-Path $PSScriptRoot 'configure-dsn.ps1') @parameters |
        Out-File -Encoding utf8 'C:\GaussDBTest\dsn.txt'
} catch {
    $_ | Format-List * -Force |
        Out-File -Encoding utf8 'C:\GaussDBTest\dsn.txt'
    exit 1
}
