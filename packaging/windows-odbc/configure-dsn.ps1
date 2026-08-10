param(
    [string]$Name = 'GaussDB507',
    [Parameter(Mandatory = $true)]
    [string]$DriverName,
    [Parameter(Mandatory = $true)]
    [string]$Server,
    [int]$Port = 5432,
    [string]$Database = 'gdbdrv_m_test',
    [ValidateSet('32-bit', '64-bit')]
    [string]$Platform = '64-bit'
)

$ErrorActionPreference = 'Stop'

$existing = Get-OdbcDsn -Name $Name -DsnType System -Platform $Platform -ErrorAction SilentlyContinue
if ($existing) {
    Remove-OdbcDsn -Name $Name -DsnType System -Platform $Platform
}

Add-OdbcDsn `
    -Name $Name `
    -DriverName $DriverName `
    -DsnType System `
    -Platform $Platform `
    -SetPropertyValue @(
        "Servername=$Server",
        "Port=$Port",
        "Database=$Database",
        'SSLmode=prefer'
    )

Get-OdbcDsn -Name $Name -DsnType System -Platform $Platform | Format-List

