<?php
// includes/user_bottom_nav.php — shared bottom nav for user-facing pages
// Set $__navActive = 'home' | 'booking' | 'health' | 'account' before including
// Set $__navBase = '' (default, same dir) or '../user/' (when used from another module)
$__navActive = $__navActive ?? '';
$__navBase   = $__navBase ?? '';
if (!function_exists('_navClass')) {
    function _navClass(string $key, string $active): string {
        return $key === $active
            ? 'flex flex-col items-center gap-1.5 text-[#2e9e63] transition-all scale-110'
            : 'flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500';
    }
}
?>
<nav class="fixed bottom-0 left-0 right-0 z-[70] bg-white/90 backdrop-blur-2xl border-t border-slate-50 px-8 py-4 pb-10 flex justify-between items-center max-w-md mx-auto shadow-[0_-20px_40px_rgba(0,0,0,0.04)]">
    <button onclick="window.location.href='<?= $__navBase ?>hub.php'" class="<?= _navClass('home', $__navActive) ?>">
        <i class="fa-solid fa-house-chimney text-xl"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.1em]">Home</span>
    </button>
    <button onclick="window.location.href='<?= $__navBase ?>my_bookings.php'" class="<?= _navClass('booking', $__navActive) ?>">
        <i class="fa-solid fa-calendar-day text-xl"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.1em]">Booking</span>
    </button>
    <div class="relative -mt-14">
        <button onclick="window.location.href='<?= $__navBase ?>hub.php#camps'" class="w-16 h-16 bg-[#2e9e63] rounded-[1.8rem] rotate-45 flex items-center justify-center text-white shadow-[0_15px_30px_rgba(46,158,99,0.4)] border-[6px] border-[#F8FAFF] active:scale-90 transition-all group">
            <i class="fa-solid fa-plus text-2xl -rotate-45 group-hover:scale-125 transition-transform"></i>
        </button>
    </div>
    <button onclick="window.location.href='<?= $__navBase ?>hub.php#health'" class="<?= _navClass('health', $__navActive) ?>">
        <i class="fa-solid fa-heart-pulse text-xl"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.1em]">Health</span>
    </button>
    <button onclick="window.location.href='<?= $__navBase ?>profile.php'" class="<?= _navClass('account', $__navActive) ?>">
        <i class="fa-solid fa-user-ninja text-xl"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.1em]">Account</span>
    </button>
</nav>
