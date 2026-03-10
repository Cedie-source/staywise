<footer class="bg-dark text-light py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-home me-2"></i>StayWise</h5>
                    <p class="mb-0">AI-Enabled Rental Management Platform</p>
                    <small class="text-muted">Simplifying property management for small rental owners</small>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">&copy; 2026 StayWise. All rights reserved.</p>
                    <small class="text-muted">Developed for Capstone Project</small>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo isset($base_url) ? $base_url : '/'; ?>assets/js/main.js"></script>
    <script>
    // Ensure Bootstrap modals render above all stacking contexts
    // Move modals to document.body on show to avoid issues from transformed/stacked parents
    document.addEventListener('DOMContentLoaded', function(){
        try {
            document.querySelectorAll('.modal').forEach(function(modalEl){
                modalEl.addEventListener('show.bs.modal', function(){
                    try {
                        if (modalEl.parentNode !== document.body) {
                            document.body.appendChild(modalEl);
                        }
                    } catch(_) {}
                });
            });
        } catch(_) {}
    });
    </script>
    <script>
    // Preserve sidebar scroll position across navigations; do not change window scroll
    (function(){
        const sidebarKey = 'sidebarScroll'; // global key so it persists across pages
        document.addEventListener('DOMContentLoaded', function(){
            // Restore sidebar scroll position if available
            try {
                var sb = document.getElementById('sidebar');
                if (!sb) sb = document.querySelector('.sidebar-custom');
                var sc = sb ? (sb.querySelector(':scope > div:first-child') || sb) : null;
                if (sc) {
                    var sv = sessionStorage.getItem(sidebarKey);
                    if (sv !== null) {
                        var sy = parseInt(sv, 10);
                        if (!isNaN(sy)) {
                            if (typeof sc.scrollTo === 'function') {
                                sc.scrollTo({ top: sy, behavior: 'auto' });
                            } else {
                                sc.scrollTop = sy;
                            }
                        }
                    }
                }
            } catch(_) {}
        });
        window.addEventListener('beforeunload', function(){
            try {
                var sb = document.getElementById('sidebar');
                if (!sb) sb = document.querySelector('.sidebar-custom');
                var sc = sb ? (sb.querySelector(':scope > div:first-child') || sb) : null;
                if (sc) sessionStorage.setItem(sidebarKey, String(sc.scrollTop || 0));
            } catch(_) {}
        });

        // Prevent empty-hash links from jumping to top
        document.addEventListener('click', function(e){
            var a = e.target.closest && e.target.closest('a[href]');
            if (!a) return;
            var href = (a.getAttribute('href') || '').trim();
            if (href === '#' || href === '' || href === '#!' || href === '#0') {
                e.preventDefault();
            }
        }, true);
    })();
    </script>
</body>
</html>