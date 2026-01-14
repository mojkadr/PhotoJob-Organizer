# Build script for PhotoJob Organizer
# Tworzy paczkę ZIP z wtyczką

# Ustaw katalog roboczy na katalog skryptu
Set-Location $PSScriptRoot

$version = "1.0.5"
$buildDir = "temp-build"
$pluginDir = "$buildDir\photojob-organizer"
$releaseDir = "releases"
$zipFile = "$releaseDir\photojob-organizer-$version.zip"

# Usuń stary build
if (Test-Path $buildDir) {
    Remove-Item -Recurse -Force $buildDir
}

# Stwórz strukturę katalogów
New-Item -ItemType Directory -Path $pluginDir | Out-Null

# Kopiuj pliki
Copy-Item photojob-organizer.php $pluginDir\
Copy-Item -Recurse includes $pluginDir\
Copy-Item -Recurse admin $pluginDir\

# Utwórz katalog releases jeśli nie istnieje
if (-not (Test-Path $releaseDir)) {
    New-Item -ItemType Directory -Path $releaseDir | Out-Null
}

# Usuń stary ZIP jeśli istnieje
if (Test-Path $zipFile) {
    Remove-Item -Force $zipFile
}

# Utwórz ZIP
Compress-Archive -Path "$buildDir\photojob-organizer" -DestinationPath $zipFile -CompressionLevel Optimal

# Sprzątanie
Remove-Item -Recurse -Force $buildDir

Write-Host "Paczka $zipFile została utworzona pomyślnie!" -ForegroundColor Green

# Weryfikacja
Write-Host "`nZawartość archiwum:" -ForegroundColor Cyan
powershell -Command "Add-Type -Assembly System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::OpenRead('$zipFile').Entries.FullName"
