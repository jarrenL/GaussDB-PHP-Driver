param(
    [Parameter(Mandatory = $true)]
    [string]$X86InstallerPath,
    [Parameter(Mandatory = $true)]
    [string]$X64InstallerPath,
    [string]$SharedInstallDirectory = 'C:\Program Files\gsqlODBC',
    [string]$X86InstallDirectory = 'C:\Program Files (x86)\gsqlODBC'
)

$ErrorActionPreference = 'Stop'

function Install-OdbcPackage([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Installer not found: $Path" }
    $process = Start-Process -FilePath $Path -ArgumentList '/S' -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw "ODBC installer failed with exit code $($process.ExitCode): $Path" }
}

function Get-PeMachine([string]$Path) {
    $stream = [System.IO.File]::OpenRead($Path)
    try {
        $reader = [System.IO.BinaryReader]::new($stream)
        $stream.Position = 0x3c
        $peOffset = $reader.ReadInt32()
        $stream.Position = $peOffset + 4
        return $reader.ReadUInt16()
    } finally {
        $stream.Dispose()
    }
}

Install-OdbcPackage $X86InstallerPath
if (-not (Test-Path -LiteralPath $SharedInstallDirectory -PathType Container)) {
    throw "X86 installer did not create $SharedInstallDirectory"
}
if (Test-Path -LiteralPath $X86InstallDirectory) {
    Remove-Item -LiteralPath $X86InstallDirectory -Recurse -Force
}
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $X86InstallDirectory) | Out-Null
Copy-Item -LiteralPath $SharedInstallDirectory -Destination $X86InstallDirectory -Recurse

# The vendor installers share one destination. Reinstall X64 after preserving X86.
Install-OdbcPackage $X64InstallerPath

$driverNames = @('GaussDB Unicode', 'GaussDB ANSI', 'gsqlodbc')
$x86RegistryRoot = 'HKLM:\SOFTWARE\WOW6432Node\ODBC\ODBCINST.INI'
foreach ($driverName in $driverNames) {
    $registryPath = Join-Path $x86RegistryRoot $driverName
    if (-not (Test-Path $registryPath)) { continue }
    $current = Get-ItemProperty $registryPath
    foreach ($property in @('Driver', 'Setup')) {
        $oldPath = $current.$property
        if ([string]::IsNullOrWhiteSpace($oldPath)) { continue }
        $newPath = Join-Path $X86InstallDirectory (Split-Path -Leaf $oldPath)
        if (-not (Test-Path -LiteralPath $newPath -PathType Leaf)) {
            throw "Missing X86 $property library: $newPath"
        }
        Set-ItemProperty -Path $registryPath -Name $property -Value $newPath
    }
}

$x64Driver = (Get-ItemProperty 'HKLM:\SOFTWARE\ODBC\ODBCINST.INI\GaussDB Unicode').Driver
$x86Driver = (Get-ItemProperty 'HKLM:\SOFTWARE\WOW6432Node\ODBC\ODBCINST.INI\GaussDB Unicode').Driver
$x64Machine = Get-PeMachine $x64Driver
$x86Machine = Get-PeMachine $x86Driver
if ($x64Machine -ne 0x8664) { throw "Expected AMD64 ODBC DLL, got PE machine 0x$($x64Machine.ToString('x4')): $x64Driver" }
if ($x86Machine -ne 0x014c) { throw "Expected X86 ODBC DLL, got PE machine 0x$($x86Machine.ToString('x4')): $x86Driver" }

[pscustomobject]@{
    X64Driver = $x64Driver
    X64Machine = '0x8664'
    X86Driver = $x86Driver
    X86Machine = '0x014c'
} | Format-List
