/* ============================================================================
   SYNDERAI — main.js
   - scroll-reveal for [data-animate] cards
   - "show more" toggle for example-listing tables
   - NEW: mobile drawer (hamburger) + grouped-dropdown / accordion controller
   Guarded so it runs at most once even if injected twice.
   ========================================================================== */
if (window.__synderaiMainLoaded) { /* already ran */ } else {
  window.__synderaiMainLoaded = true;

  /* --------------------------------------------------------------------------
     Scroll-reveal (cards start hidden only when JS is present — see styles.css)
     -------------------------------------------------------------------------- */
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.opacity = 1;
        entry.target.style.transform = 'translateY(0)';
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2 });

  document.querySelectorAll('[data-animate]').forEach(el => observer.observe(el));

  /* --------------------------------------------------------------------------
     Navigation: hamburger drawer + dropdown groups
     -------------------------------------------------------------------------- */
  function setupNavigation() {
    const body     = document.body;
    const toggle   = document.getElementById('menuToggle');
    const navLinks = document.getElementById('navLinks');
    const backdrop = document.getElementById('navBackdrop');
    const MOBILE   = '(max-width: 860px)';

    const isMobile = () => window.matchMedia(MOBILE).matches;

    /* ----- drawer open / close ----- */
    function openDrawer() {
      body.classList.add('nav-open');
      if (toggle)   toggle.setAttribute('aria-expanded', 'true');
      if (backdrop) backdrop.hidden = false;
    }
    function closeDrawer() {
      body.classList.remove('nav-open');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
      if (backdrop) {
        backdrop.hidden = true;
      }
      /* collapse any open accordion groups */
      document.querySelectorAll('.has-dropdown.open').forEach(closeGroup);
    }

    if (toggle) {
      toggle.addEventListener('click', () => {
        body.classList.contains('nav-open') ? closeDrawer() : openDrawer();
      });
    }
    if (backdrop) backdrop.addEventListener('click', closeDrawer);

    /* ----- dropdown groups (hover on desktop via CSS; click everywhere) ----- */
    const groups = Array.from(document.querySelectorAll('.has-dropdown'));

    function openGroup(group) {
      const btn = group.querySelector('.dropdown-toggle');
      group.classList.add('open');
      if (btn) btn.setAttribute('aria-expanded', 'true');
    }
    function closeGroup(group) {
      const btn = group.querySelector('.dropdown-toggle');
      group.classList.remove('open');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    groups.forEach(group => {
      const btn = group.querySelector('.dropdown-toggle');
      if (!btn) return;
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const willOpen = !group.classList.contains('open');
        /* one group open at a time */
        groups.forEach(g => { if (g !== group) closeGroup(g); });
        willOpen ? openGroup(group) : closeGroup(group);
      });
    });

    /* close drawer after tapping a real destination link */
    navLinks && navLinks.querySelectorAll('a[href]').forEach(a => {
      a.addEventListener('click', () => { if (isMobile()) closeDrawer(); });
    });

    /* click outside closes any open desktop dropdown */
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.has-dropdown')) {
        groups.forEach(closeGroup);
      }
    });

    /* Escape closes drawer + dropdowns */
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        groups.forEach(closeGroup);
        if (body.classList.contains('nav-open')) {
          closeDrawer();
          if (toggle) toggle.focus();
        }
      }
    });

    /* leaving mobile width resets drawer state */
    let wasMobile = isMobile();
    window.addEventListener('resize', () => {
      const nowMobile = isMobile();
      if (wasMobile && !nowMobile) {
        closeDrawer();
        groups.forEach(closeGroup);
      }
      wasMobile = nowMobile;
    });
  }

  /* --------------------------------------------------------------------------
     Example-listing "show more / fewer" per table
     -------------------------------------------------------------------------- */
  function setupTableToggle(table) {
    const onDemandRows = Array.from(
      table.querySelectorAll('tbody tr.showondemand, tr.showondemand')
    );
    if (onDemandRows.length === 0) return;

    onDemandRows.forEach(tr => tr.style.display = 'none');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'table-toggle gradient-btn';
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = `Show ${onDemandRows.length} more`;

    btn.addEventListener('click', () => {
      const collapsed = onDemandRows[0].style.display === 'none';
      onDemandRows.forEach(tr => tr.style.display = collapsed ? 'table-row' : 'none');
      if (collapsed) {
        btn.textContent = 'Show fewer';
        btn.setAttribute('aria-expanded', 'true');
      } else {
        btn.textContent = `Show ${onDemandRows.length} more`;
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    table.after(btn);
  }

  /* --------------------------------------------------------------------------
     Boot
     -------------------------------------------------------------------------- */
  function init() {
    setupNavigation();
    document.querySelectorAll('table.toggle-table').forEach(setupTableToggle);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}