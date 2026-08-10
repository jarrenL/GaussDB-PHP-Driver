$paths = @(
    'C:\Windows\Temp\gauss-password.txt',
    'C:\Windows\Temp\gauss-connection-string.txt'
)

Remove-Item -Force -ErrorAction SilentlyContinue $paths
