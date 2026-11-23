# Ambiente local do Hostrema a partir do Windows: roda infra/local/hostrema-local.sh no Git Bash (do
# Git for Windows) ou, sem ele, no Ubuntu do WSL. Precisa do Docker Desktop aberto.
# Uso (PowerShell): .\infra\local\hostrema-local.ps1 [comando]
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
$ErrorActionPreference = 'Stop'

if (-not (Get-Command docker.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'Instale e abra o Docker Desktop: https://www.docker.com/products/docker-desktop/'
    exit 1
}

# Git Bash: o bash.exe que vem com o Git for Windows.
$candidates = @()
if ($env:ProgramFiles) { $candidates += Join-Path $env:ProgramFiles 'Git\bin\bash.exe' }
$git = Get-Command git.exe -ErrorAction SilentlyContinue
if ($git) { $candidates += Join-Path (Split-Path (Split-Path $git.Source)) 'bin\bash.exe' }
$bash = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($bash) {
    & $bash (($PSScriptRoot -replace '\\', '/') + '/hostrema-local.sh') @Arguments
    exit $LASTEXITCODE
}

# Sem Git Bash: o Ubuntu do WSL, com a integração do Docker Desktop ligada para ele.
$install = 'Instale o Git for Windows (https://git-scm.com/download/win), que traz o Git Bash, e rode de novo.'
if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    Write-Host $install
    exit 1
}
$distributions = (wsl.exe --list --quiet) -replace "`0", '' | Where-Object { $_ -match '\S' } | ForEach-Object { $_.Trim() }
$distribution = $distributions | Where-Object { $_ -like 'Ubuntu*' } | Select-Object -First 1
if (-not $distribution) {
    Write-Host $install
    exit 1
}
$directory = (wsl.exe --distribution $distribution --exec wslpath -a ($PSScriptRoot -replace '\\', '/')).Trim()
wsl.exe --distribution $distribution --cd $directory --exec bash ./hostrema-local.sh @Arguments
exit $LASTEXITCODE
