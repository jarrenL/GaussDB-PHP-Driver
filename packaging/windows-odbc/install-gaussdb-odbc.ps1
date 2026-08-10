param(
    [Parameter(Mandatory = $true)]
    [string]$InstallerPath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $InstallerPath -PathType Leaf)) {
    throw "Installer not found: $InstallerPath"
}

$process = Start-Process -FilePath $InstallerPath -ArgumentList '/S' -Wait -PassThru
if ($process.ExitCode -ne 0) {
    throw "GaussDB ODBC installer failed with exit code $($process.ExitCode)"
}

$drivers = Get-OdbcDriver | Where-Object {
    $_.Name -match 'Gauss|gsql|PostgreSQL'
}
if (-not $drivers) {
    throw 'Installation completed but no GaussDB-compatible ODBC driver was registered'
}

$drivers | Format-Table Name, Platform, Attribute -AutoSize

