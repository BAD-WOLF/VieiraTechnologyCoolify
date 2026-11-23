# Hostrema em produção na AWS, a partir do Windows: roda infra/prod/hostrema-prod.sh no WSL (Ubuntu),
# instalando o Ubuntu do WSL na primeira vez. Uso (PowerShell): .\infra\prod\hostrema-prod.ps1 [comando]
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
$ErrorActionPreference = 'Stop'

if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'O WSL não está instalado. Abra o PowerShell como administrador, rode "wsl --install" e reinicie o Windows.'
    exit 1
}
$distributions = (wsl.exe --list --quiet) -replace "`0", '' | Where-Object { $_ -match '\S' } | ForEach-Object { $_.Trim() }
$distribution = $distributions | Where-Object { $_ -like 'Ubuntu*' } | Select-Object -First 1
if (-not $distribution) {
    Write-Host 'Instalando o Ubuntu no WSL (uma vez). Ao terminar, crie o usuário do Ubuntu e rode este script de novo.'
    wsl.exe --install --distribution Ubuntu
    exit 0
}
$directory = (wsl.exe --distribution $distribution --exec wslpath -a ($PSScriptRoot -replace '\\', '/')).Trim()
wsl.exe --distribution $distribution --cd $directory --exec bash ./hostrema-prod.sh @Arguments
exit $LASTEXITCODE
