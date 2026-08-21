param(
    [Parameter(Mandatory = $true)]
    [string]$Path,
    [Parameter(Mandatory = $true)]
    [string]$EnvironmentVariable
)

$ErrorActionPreference = 'Stop'
$value = [Environment]::GetEnvironmentVariable($EnvironmentVariable, 'Process')
if ([string]::IsNullOrEmpty($value)) {
    throw "Process environment variable $EnvironmentVariable is required"
}

$parent = Split-Path -Parent $Path
New-Item -ItemType Directory -Force -Path $parent | Out-Null
New-Item -ItemType File -Force -Path $Path | Out-Null
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $Path /inheritance:r /grant:r "${identity}:(F)" 'SYSTEM:(F)' | Out-Null
if ($LASTEXITCODE -ne 0) { throw "Unable to restrict ACL for $Path" }
[System.IO.File]::WriteAllText($Path, $value, [System.Text.UTF8Encoding]::new($false))
