# restore_thai.ps1
$baseDir = "c:\xampp\htdocs\e-campaignv2\archive\e_Borrow"
$files = Get-ChildItem -Path $baseDir -Recurse -Filter *.php

# Source encoding: UTF-8 (what it is saved as now)
$utf8 = [System.Text.Encoding]::UTF8
# Recovery encoding: Windows-874 (what it was incorrectly read as)
$thai = [System.Text.Encoding]::GetEncoding(874)

foreach ($file in $files) {
    try {
        # 1. Read the corrupted text as a string from the UTF-8 file
        $corruptedText = [System.IO.File]::ReadAllText($file.FullName, $utf8)
        
        # 2. Convert that string back to the bytes it would have been in Thai (ANSI/874)
        $originalBytes = $thai.GetBytes($corruptedText)
        
        # 3. Write those bytes back to the file as actual UTF-8 (without BOM)
        # This works because the "corruptedText" in CP874 bytes ARE the original UTF-8 bytes if the file was UTF-8.
        # IF the file was originally TIS-620, then the originalBytes ARE the TIS-620 bytes.
        # Either way, we want the final file to be UTF-8.
        
        # We need to determine if we should write as UTF-8 or keep TIS-620. 
        # For modern web, UTF-8 is best. 
        # If originalBytes are TIS-620, we need to convert them to UTF-8.
        
        # Let's try to decode originalBytes as UTF-8 first to see if it's valid.
        try {
            $recoveredText = [System.Text.Encoding]::UTF8.GetString($originalBytes)
            # If it works and contains Thai, originalBytes was UTF-8.
            [System.IO.File]::WriteAllText($file.FullName, $recoveredText, (New-Object System.Text.UTF8Encoding($false)))
            Write-Host "Restored (as UTF8): $($file.FullName)"
        } catch {
            # If not valid UTF-8, maybe it was TIS-620.
            $recoveredText = $thai.GetString($originalBytes)
            [System.IO.File]::WriteAllText($file.FullName, $recoveredText, (New-Object System.Text.UTF8Encoding($false)))
            Write-Host "Restored (from TIS620 to UTF8): $($file.FullName)"
        }
    } catch {
        Write-Error "Error: $($_.Exception.Message)"
    }
}
