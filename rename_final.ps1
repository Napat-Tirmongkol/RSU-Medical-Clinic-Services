# rename_final.ps1
$replacements = @{
    "med_equipment_items" = "borrow_items"
    "med_equipment_types" = "borrow_categories"
    "med_transactions"    = "borrow_records"
    "med_fines"           = "borrow_fines"
    "med_payments"        = "borrow_payments"
    "med_logs"            = "sys_activity_logs"
    "med_students"        = "sys_users"
    "med_users"           = "sys_staff"
    "med_staff"           = "sys_staff"
    "admin_users"         = "sys_admins"
    "vac_vaccines"        = "vac_list"
}

$files = Get-ChildItem -Recurse -Include *.php

foreach ($file in $files) {
    try {
        $content = Get-Content $file.FullName -Raw
        $newContent = $content
        
        foreach ($old in $replacements.Keys) {
            $new = $replacements[$old]
            # Case-insensitive replace
            $newContent = $newContent -replace [regex]::Escape($old), $new
        }
        
        if ($newContent -ne $content) {
            Set-Content $file.FullName $newContent -NoNewline -Encoding UTF8
            Write-Host "Replaced in: $($file.FullName)"
        }
    } catch {
        Write-Error "Error processing $($file.FullName): $($_.Exception.Message)"
    }
}
