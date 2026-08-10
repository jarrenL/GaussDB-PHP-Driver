param(
    [string]$Server = '192.168.64.1',
    [int]$Port = 15432,
    [string]$OutputDirectory = 'C:\GaussDBTest'
)

$ErrorActionPreference = 'Continue'
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null

Get-OdbcDriver -Platform '64-bit' |
    Where-Object Name -Like '*Gauss*' |
    Format-List * |
    Out-File -Encoding utf8 (Join-Path $OutputDirectory 'drivers64.txt')

Test-NetConnection $Server -Port $Port |
    Format-List |
    Out-File -Encoding utf8 (Join-Path $OutputDirectory 'network.txt')

Get-OdbcDsn -DsnType System -Platform '64-bit' |
    Format-List * |
    Out-File -Encoding utf8 (Join-Path $OutputDirectory 'dsn64.txt')
