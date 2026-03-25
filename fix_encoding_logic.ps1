# fix_encoding.ps1
$baseDir = "c:\xampp\htdocs\e-campaignv2\archive\e_Borrow"
$files = Get-ChildItem -Path $baseDir -Recurse -Filter *.php

# Get Windows-874 encoding (Thai)
$thaiEnc = [System.Text.Encoding]::GetEncoding(874)
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

foreach ($file in $files) {
    try {
        # Read the corrupted file as bytes
        $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
        
        # We suspect the file was originally TIS-620, but was saved as UTF-8 while corrupted.
        # Actually, the most common case of "เธฃเธฐเธšเธš" is UTF-8 bytes interpreted as Windows-1252 or 874.
        # If I simply read it now as UTF-8, I'll get the same gibberish.
        
        # Let's try to convert back. If we read it as UTF-8 and it looks like gibberish, 
        # it means the UTF-8 bytes now represent the gibberish.
        # This is hard to reverse perfectly unless we know the exact chain.
        
        # HOWEVER, if the file was TIS-620 and I read it as UTF-8 (incorrectly) and saved, 
        # it literally saved the "question marks" or "weird chars" as UTF-8.
        
        # I will try to treat the file content as having been read incorrectly and try to re-map.
        # But a safer bet is searching for common mojibake patterns and replacing them, 
        # or if the user has a backup... but I don't.
        
        # Let's try to read it as UTF-8 first.
        $content = [System.IO.File]::ReadAllText($file.FullName)
        
        # If it looks like Mojibake, maybe we can fix it by re-interpreting the bytes.
        # "เธ" is 0xE0 0xB8 0x94 in UTF-8? No.
        
        # Let's assume the files WERE TIS-620 and were written over as UTF8.
        # Actually, I'll just try to restore the most important file manually 
        # or use a mapping if I can.
    } catch {
        Write-Error "Error: $($_.Exception.Message)"
    }
}
