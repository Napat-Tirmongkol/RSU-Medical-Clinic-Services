# remove_bom.ps1
$baseDir = "c:\xampp\htdocs\e-campaignv2\archive\e_Borrow"
$files = Get-ChildItem -Path $baseDir -Recurse -Filter *.php

foreach ($file in $files) {
    try {
        # Read bytes
        $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
        
        # Check for UTF8 BOM (EF BB BF)
        if ($bytes.Count -gt 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            # Create new array without the first 3 bytes
            $newBytes = New-Object byte[] ($bytes.Length - 3)
            [Array]::Copy($bytes, 3, $newBytes, 0, $newBytes.Length)
            
            # Write back
            [System.IO.File]::WriteAllBytes($file.FullName, $newBytes)
            Write-Host "Removed BOM from: $($file.FullName)"
        }
    } catch {
        Write-Error "Error: $($_.Exception.Message)"
    }
}
