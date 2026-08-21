param(
    [string]$PhpPath = 'C:\GaussDBTest\php-8.3.8-x64\php.exe',
    [string]$TestPath = 'C:\GaussDBTest\php_pdo_contract.php',
    [string]$OutputPath = 'C:\GaussDBTest\windows-x64.json',
    [string]$Profile = 'windows-x64',
    [string]$ConnectionString = $env:GAUSS_ODBC_CONNECTION_STRING,
    [string]$ConnectionStringFile = 'C:\Windows\Temp\gauss-connection-string.txt',
    [string]$User = 'gauss_php_test',
    [string]$Password = $env:GAUSS_PASSWORD,
    [string]$PasswordFile = 'C:\Windows\Temp\gauss-password.txt'
)

$ErrorActionPreference = 'Stop'
function Assert-SecretFileAcl([string]$Path) {
    $acl = Get-Acl -LiteralPath $Path
    if (-not $acl.AreAccessRulesProtected) {
        throw "Secret file inherits ACL entries: $Path"
    }
    $forbiddenSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545')
    foreach ($rule in $acl.Access) {
        $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
        if ($rule.AccessControlType -eq 'Allow' -and $sid -in $forbiddenSids) {
            throw "Secret file grants access to a broad principal ($sid): $Path"
        }
    }
}

$passwordLoadedFromFile = $false
$connectionLoadedFromFile = $false
$exitCode = 1
try {
    if ([string]::IsNullOrWhiteSpace($ConnectionString) -and (Test-Path $ConnectionStringFile)) {
        Assert-SecretFileAcl $ConnectionStringFile
        $ConnectionString = (Get-Content -Raw -Path $ConnectionStringFile).Trim()
        $connectionLoadedFromFile = $true
    }
    if ([string]::IsNullOrWhiteSpace($Password) -and (Test-Path $PasswordFile)) {
        Assert-SecretFileAcl $PasswordFile
        $Password = (Get-Content -Raw -Path $PasswordFile).Trim()
        $passwordLoadedFromFile = $true
    }
    if ([string]::IsNullOrWhiteSpace($ConnectionString)) { throw 'GAUSS_ODBC_CONNECTION_STRING is required' }
    if ([string]::IsNullOrWhiteSpace($Password)) { throw 'GAUSS_PASSWORD is required' }

    $env:GAUSS_TEST_PROFILE = $Profile
    $env:GAUSS_TEST_DRIVER = 'odbc'
    $env:GAUSS_ODBC_CONNECTION_STRING = $ConnectionString
    $env:GAUSS_USER = $User
    $env:GAUSS_PASSWORD = $Password

    $output = & $PhpPath $TestPath 2>&1
    $exitCode = $LASTEXITCODE
    $output | Out-File -FilePath $OutputPath -Encoding utf8
} catch {
    @('{"runner_error":true}'; $_ | Out-String) |
        Out-File -FilePath $OutputPath -Encoding utf8
    $exitCode = 1
} finally {
    Remove-Item Env:GAUSS_PASSWORD -ErrorAction SilentlyContinue
    if ($passwordLoadedFromFile -or $PasswordFile -eq 'C:\Windows\Temp\gauss-password.txt') {
        Remove-Item -Force -ErrorAction SilentlyContinue $PasswordFile
    }
    if ($connectionLoadedFromFile -or $ConnectionStringFile -eq 'C:\Windows\Temp\gauss-connection-string.txt') {
        Remove-Item -Force -ErrorAction SilentlyContinue $ConnectionStringFile
    }
}
exit $exitCode
