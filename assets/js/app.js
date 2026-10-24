/**
 * TruckBook Pro - Client-side Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
  // 1. Sidebar Toggle on Mobile
  const sidebar = document.getElementById('app-sidebar');
  const toggleBtn = document.getElementById('sidebar-toggle');
  const backdrop = document.getElementById('sidebar-backdrop');

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function () {
      sidebar.classList.toggle('show');
      if (backdrop) {
        backdrop.classList.toggle('show');
      }
    });
  }

  if (backdrop) {
    backdrop.addEventListener('click', function () {
      sidebar.classList.remove('show');
      backdrop.classList.remove('show');
    });
  }

  // 2. Auto-dismiss flash alerts after 5 seconds
  const autoAlerts = document.querySelectorAll('.alert-dismissible');
  autoAlerts.forEach(function (alert) {
    setTimeout(function () {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      if (bsAlert) {
        bsAlert.close();
      }
    }, 5000);
  });

  // 3. Global Confirmation for Delete / Dangerous Actions
  document.addEventListener('click', function (e) {
    const target = e.target.closest('[data-confirm]');
    if (target) {
      const message = target.getAttribute('data-confirm') || 'Are you sure you want to proceed with this action?';
      if (!confirm(message)) {
        e.preventDefault();
      }
    }
  });

  // 4. Quick CSRF Fetch Wrapper
  window.truckFetch = async function (url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    
    options.headers = {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken || '',
      ...(options.headers || {})
    };

    try {
      const response = await fetch(url, options);
      return await response.json();
    } catch (err) {
      console.error('Network or parse error in truckFetch:', err);
      throw err;
    }
  };
});
