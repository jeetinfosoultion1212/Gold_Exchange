// Share Modal Functionality
// This file handles share modal interactions
// Safe to load on all pages - will only initialize if elements exist

(function() {
    'use strict';
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShareModal);
    } else {
        initShareModal();
    }
    
    function initShareModal() {
        // Check if share modal elements exist on this page
        const shareButton = document.querySelector('[data-share-modal]');
        const shareModal = document.getElementById('shareModal');
        
        // Only initialize if elements exist
        if (shareButton && shareModal) {
            shareButton.addEventListener('click', function(e) {
                e.preventDefault();
                shareModal.classList.remove('hidden');
            });
        }
        
        // Close modal when clicking outside or on close button
        const closeShareModal = document.getElementById('closeShareModal');
        if (closeShareModal && shareModal) {
            closeShareModal.addEventListener('click', function() {
                shareModal.classList.add('hidden');
            });
        }
        
        // Close modal on Escape key
        if (shareModal) {
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !shareModal.classList.contains('hidden')) {
                    shareModal.classList.add('hidden');
                }
            });
        }
    }
})();

