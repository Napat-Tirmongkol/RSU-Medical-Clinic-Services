<?php
// portal/_partials/ai_assistant.php
?>
<div class="ai-assistant-container flex flex-col bg-white">
    <div class="px-8 py-5 border-b border-gray-100 flex items-center justify-between bg-white/80 backdrop-blur-md sticky top-0 z-10">
        <div>
            <h2 class="text-xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-600"></i> AI Assistant
            </h2>
            <p class="text-xs text-gray-400 mt-0.5">ผู้ช่วยอัจฉริยะวิเคราะห์ข้อมูลแคมเปญด้วย Gemini AI</p>
        </div>
        <div class="flex items-center gap-3">
             <div class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-600 rounded-lg border border-purple-100">
                <span class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></span>
                <span class="text-[10px] font-black uppercase tracking-wider">Gemini 1.5 Flash</span>
             </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 relative">
        <iframe src="../admin/ai_assistant.php?embed=1" class="w-full h-full border-none" id="aiAssistantIframe"></iframe>
    </div>
</div>

<script>
(function() {
    const iframe = document.getElementById('aiAssistantIframe');
    function syncTheme() {
        const isDark = document.body.getAttribute('data-theme') === 'dark';
        const currentSrc = new URL(iframe.src);
        if (isDark) currentSrc.searchParams.set('theme', 'dark');
        else currentSrc.searchParams.delete('theme');
        
        if (iframe.src !== currentSrc.href) {
            iframe.src = currentSrc.href;
        }
    }
    
    // Initial sync
    syncTheme();
    
    // Listen for theme changes (using MutationObserver since theme is on body)
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
                syncTheme();
            }
        });
    });
    observer.observe(document.body, { attributes: true });
})();
</script>

<style>
#section-ai_assistant {
    display: flex;
    flex-direction: column;
}
.ai-assistant-container {
    height: 100%;
    width: 100%;
}
</style>
