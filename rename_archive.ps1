# rename_archive.ps1
$baseDir = "c:\xampp\htdocs\e-campaignv2\archive\e_Borrow"
$files = Get-ChildItem -Path $baseDir -Recurse -Filter *.php

foreach ($file in $files) {
    $content = Get-Content $file.FullName -Raw
    $newContent = $content -replace "deprecated", "archive"
    
    if ($newContent -ne $content) {
        Set-Content $file.FullName $newContent -NoNewline -Encoding UTF8
        Write-Host "Fixed path in: $($file.FullName)"
    }
}
