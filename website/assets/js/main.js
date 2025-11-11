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


document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.toggle-table').forEach(setupTableToggle);
  });

  function setupTableToggle(table) {
    // Collect only the rows that should be hidden initially
    const onDemandRows = Array.from(
      table.querySelectorAll('tbody tr.showondemand, tr.showondemand')
    );

    if (onDemandRows.length === 0) return; // nothing to toggle → no button

    // Hide them (progressive enhancement: done via JS so no-JS shows all)
    onDemandRows.forEach(tr => tr.style.display = 'none');

    // Create a per-table button
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'table-toggle gradient-btn';
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = `Show ${onDemandRows.length} more`;

    // Toggle handler scoped to THIS table's rows only
    btn.addEventListener('click', () => {
      const collapsed = onDemandRows[0].style.display == 'none';

      onDemandRows.forEach(tr => tr.style.display = collapsed ? 'inherit' : 'none');

      if (collapsed) {
        btn.textContent = 'Show fewer';
        btn.setAttribute('aria-expanded', 'true');
      } else {
        btn.textContent = `Show ${onDemandRows.length} more`;
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    // Place the button right after the table it controls
    table.after(btn);
  }