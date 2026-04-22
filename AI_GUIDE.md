# Project Architecture & AI Instructions (e-Campaign V2)

This document serves as a "Memory" for AI assistants working on this codebase to prevent recurring UI/Logic errors.

## 1. Portal Structure (portal/index.php)
- **Main Layout**: Uses an SPA (Single Page Application) style within `portal/index.php`.
- **The Shell**: The sidebar and header are in the "App Shell".
- **Main Container**: All page sections MUST be inside `<main id="portal-main">`.
- **Section Pattern**:
  ```html
  <div id="section-NAME" class="portal-section" style="<?= $activeSection==='NAME'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
      <!-- Content here -->
  </div>
  ```
- **CRITICAL RULE**: Do NOT place new sections after the script blocks or outside the `</main>` tag. The `</main>` tag is usually around line 1700-1800. Placing code after it breaks the layout.

## 2. Database & Logging
- **Connection**: Use the `$pdo` singleton (defined in `config/db_connect.php`).
- **ISO 27001 Compliance**: Every administrative change (Add/Edit/Delete) MUST be logged using the `log_activity()` function.
- **Privilege Inventory**: Changes to Super Admin or Admin rights must be recorded in `sys_admin_privilege_inventory`.

## 3. Styling Rules
- **CSS Framework**: Custom CSS with Tailwind-like utility classes (in `portal/index.php` `<style>` tag).
- **Design Language**: Premium, clean, emerald-themed for RSU Healthcare.
- **Glassmorphism**: Use `backdrop-filter: blur(...)` for modals and floating elements.

## 4. Troubleshooting History
- **UI Breakage**: If the UI shifts to the right or becomes narrow, check if a section was accidentally placed outside the `<main>` container.
- **Log Spam**: Filter out "Already up to date" or minor read actions from error logs to avoid database bloat.
